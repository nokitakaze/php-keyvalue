<?php

    namespace NokitaKaze\KeyValue;

    use NokitaKaze\Mutex\MutexInterface;
    use NokitaKaze\Mutex\FileMutex;
    use Psr\SimpleCache\CacheInterface;

    /**
     * Class AbstractStorage
     * @package NokitaKaze\KeyValue
     * @doc http://www.php-fig.org/psr/psr-16/
     */
    abstract class AbstractStorage implements CacheInterface {
        const StorageTemporary = 0;
        const StoragePersistent = 1;
        const RegionDomain = 0;// Только в контексте этого HostName
        const RegionServer = 1;// В контексте ВСЕГО дедика
        const RegionFolder = 2;// В контексте папки домена (для разных хостов, запущенных из одной папки)

        /**
         * @var string Префикс к названиям полей ключей для бесконфликтного использования в глобалсе
         */
        protected $_prefix;

        /**
         * @var KeyValueSettings|object Настройки, переданные в конструктор и конструктором исправленные
         */
        protected $settings;

        /**
         * @var MutexInterface[]|null[]
         * Мьютекс, созданный через MutexInterface, настройки задаются конкретной реализацией
         */
        protected $_locks = [];

        /**
         * @var string|null
         */
        protected $_last_used_lock_key = null;

        /**
         * @param KeyValueSettings|object $settings
         */
        abstract function __construct($settings);

        /**
         * Дёргаем значение из kv-хранилища
         *
         * @param string       $key           Название ключа
         * @param string|mixed $default_value Дефолтное значение, если настоящее недоступно
         *
         * @return string|mixed
         */
        function get_value($key, $default_value = '') {
            $data = $this->get_value_full($key);

            return is_null($data) ? $default_value : $data->value;
        }

        /**
         * Формируем datum типа объект, который мы будем вносить в наше kv-хранилище
         *
         * @param string|integer $key   Ключ
         * @param mixed          $value Новое значение
         * @param double         $ttl   Кол-во секунд, после которых значение будет считаться просроченным
         *
         * @return object|KeyValueDatum
         */
        protected function form_datum_value($key, $value, $ttl) {
            $backtrace = debug_backtrace();
            $data = (object) [
                'key' => $key,
                'time_create' => microtime(true),
                'time_expires' => microtime(true) + $ttl,
                'host' => isset($_SERVER, $_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null,
                'value' => $value,
                'init_file' => isset($backtrace[1], $backtrace[1]['file']) ? $backtrace[1]['file'] : null,
                'init_line' => isset($backtrace[1], $backtrace[1]['line']) ? $backtrace[1]['line'] : null,
                'pid' => function_exists('posix_getpid') ? posix_getpid() : null,// @todo windows
            ];
            unset($backtrace);

            return $data;
        }

        /**
         * Пишем новое значение для key в нашем kv-хранилище
         *
         * @param string         $key   Название ключа
         * @param mixed          $value Новое значение
         * @param double|integer $ttl   Кол-во секунд, после которых значение будет считаться просроченным
         *
         * @throws KeyValueException
         */
        abstract function set_value($key, $value, $ttl = 315576000);

        /**
         * @param string $key
         *
         * @return object|null
         */
        abstract protected function get_value_full_clear($key);

        /**
         * Получение полных данных по вхождению в kv-хранилище
         *
         * @param string $key Название ключа
         *
         * @return object|KeyValueDatum|null
         */
        function get_value_full($key) {
            $ts1 = microtime(true);
            $data = $this->get_value_full_clear($key);
            self::add_profiling(microtime(true) - $ts1, static::class, 'get_value_full_clear');
            if (is_null($data)) {
                return null;
            }

            // Проверяем expires
            if (!isset($data->time_expires) or ($data->time_expires < microtime(true))) {
                return null;
            }

            return $data;
        }

        /**
         * Удаляем вхождение kv-хранилища
         *
         * @param string $key Название ключа
         */
        abstract function delete_value($key);

        /**
         * Стандартная стратегия выбора префиксов
         */
        function standard_prefix_strategy() {
            if (!isset($this->settings->region_type)) {
                $this->settings->region_type = self::RegionDomain;
            }
            if (isset($this->settings->prefix)) {
                $this->_prefix = $this->settings->prefix;

                return;
            }

            $this->_prefix = self::get_default_prefix_from_environment($this->settings->region_type);
        }

        /**
         * @param integer $region_type
         *
         * @return string
         * @throws KeyValueException
         */
        static function get_default_prefix_from_environment($region_type = self::RegionDomain) {
            switch ($region_type) {
                case self::RegionDomain:
                    return hash('sha256', preg_replace('|^(www\\.)?([a-z0-9.-]+)(\\:[0-9]+)?|', '$2',
                            strtolower(self::get_environment('host')))).'_';
                case self::RegionServer:
                    return '';
                case self::RegionFolder:
                    // @hint Мы используем sha256, а не sha512, потому что иначе у нас просто не хватит длины
                    return hash('sha256', strtolower(self::get_environment('root'))).'_';
                default:
                    throw new KeyValueException('Constructor settings is malformed. Region type can not be equal '.
                                                $region_type, 1);
            }
        }

        /**
         * @param string $key
         *
         * @return string
         * @throws KeyValueException
         */
        static function get_environment($key) {
            switch ($key) {
                case 'host':
                    return isset($_SERVER, $_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
                case 'root':
                    return isset($_SERVER, $_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
                default:
                    throw new KeyValueException('malformed get_environment parameter', 2);
            }
        }

        /**
         * @return string
         */
        function get_prefix() {
            return $this->_prefix;
        }

        /**
         * Время просрочки значения, если есть
         *
         * @param string $key
         *
         * @return double|null
         */
        function get_expires_time($key) {
            $data = $this->get_value_full($key);
            if (is_null($data)) {
                return null;
            } else {
                return $data->time_expires;
            }
        }

        /**
         * Обновляем срок жизни записи
         *
         * @param string $key Название ключа
         * @param double $ttl Кол-во секунд, после которых значение будет считаться просроченным
         * @throws KeyValueException
         */
        function set_expires_time($key, $ttl) {
            $data = $this->get_value_full($key);
            if (is_null($data)) {
                return;
            }
            $this->set_value($key, $data->value, $ttl);
        }

        /**
         * Время задания значения
         *
         * @param string $key
         *
         * @return double|null
         */
        function get_change_time($key) {
            $data = $this->get_value_full($key);
            if (is_null($data)) {
                return null;
            } else {
                return $data->time_create;
            }
        }

        /**
         * Время задания значения
         *
         * @param string $key
         *
         * @return boolean
         */
        function is_exist($key) {
            return !is_null($this->get_value_full($key));
        }

        /**
         * @var double[][]
         */
        private static $_profiling = [];

        /**
         * @param double $time
         * @param string $class
         * @param string $action
         */
        protected static function add_profiling($time, $class, $action) {
            if (!array_key_exists($class, self::$_profiling)) {
                self::$_profiling[$class] = [$action => $time];
            } elseif (!array_key_exists($action, self::$_profiling[$class])) {
                self::$_profiling[$class][$action] = $time;
            } else {
                self::$_profiling[$class][$action] += $time;
            }
        }

        /**
         * Весь профайлинг
         *
         * @return double[]|double[][]
         */
        static function get_profiling() {
            $data = [
                'class' => [],
                'action' => [],
                'all' => 0,
            ];
            foreach (self::$_profiling as $class => &$obj) {
                if (!array_key_exists($class, $data['class'])) {
                    $data['class'][$class] = 0;
                }
                foreach ($obj as $action => &$time) {
                    if (array_key_exists($action, $data['action'])) {
                        $data['action'][$action] += $time;
                    } else {
                        $data['action'][$action] = $time;
                    }
                    $data['class'][$class] += $time;
                    $data['all'] += $time;
                }
            }

            return $data;
        }

        /**
         * Создаём мьютекс, соответствующий ключу и кладём его в _locks
         *
         * @param string $key
         */
        protected function create_lock($key) {
            // @todo Любой мьютекс
            // @todo отдельный метод для роутинга мьютексов
            $this->_locks[$key] = new FileMutex([
                'name' => 'ascetkey_'.$key,
                'prefix' => '',
            ]);
        }

        /**
         * Лочим мьютекс, соответствующий ключу
         *
         * @param string $key
         */
        protected function get_lock($key) {
            if (!isset($this->_locks[$key])) {
                $this->create_lock($key);
            }
            $this->_locks[$key]->get_lock();
            $this->_last_used_lock_key = $key;
        }

        /**
         * Снимаем мьютекс
         */
        protected function release_lock() {
            if (isset($this->_locks[$this->_last_used_lock_key])) {
                $this->_locks[$this->_last_used_lock_key]->release_lock();
            }
        }

        /**
         * Удаляем все созданные мьютексы, не удаляя сам объект Storage
         *
         * @param integer|null $minimal_count_for_delete
         */
        function clear_mutex_list($minimal_count_for_delete = null) {
            if (is_null($minimal_count_for_delete) or (count($this->_locks) >= $minimal_count_for_delete)) {
                $this->_locks = [];
            }
        }

        /**
         * PSR-16 wrapper
         */

        /**
         * @param string $key
         *
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        protected function is_key_valid($key) {
            if (!is_string($key) and !is_integer($key)) {
                throw new KeyValueException('Invalid Argument');
            }
            if (preg_match('_[\\{\\}\\(\\)\\/\\@:]_', $key)) {
                throw new KeyValueException('Key contains forbidden symbol');
            }
        }

        /**
         * @param string $key
         * @param null   $default
         *
         * @return mixed
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        function get($key, $default = null) {
            $this->is_key_valid($key);

            return $this->get_value($key, $default);
        }

        /**
         * @param string $key
         *
         * @return boolean
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        function delete($key) {
            $this->is_key_valid($key);
            $this->delete_value($key);

            return true;
        }

        /**
         * @param string $key
         * @param mixed  $value
         * @param null   $ttl
         *
         * @return boolean
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        function set($key, $value, $ttl = null) {
            $this->is_key_valid($key);
            try {
                $this->set_value($key, $value, !is_null($ttl) ? $ttl : 10 * 365.25 * 24 * 3600);
            } catch (KeyValueException $e) {
                return false;
            }

            return true;
        }

        /**
         * @codeCoverageIgnore
         */
        function clear() {
            // @hint It should be implemented in children
        }

        /**
         * @param \iterable $keys
         * @param null      $default
         *
         * @return array
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        function getMultiple($keys, $default = null) {
            $data = [];
            foreach ($keys as &$key) {
                $data[$key] = $this->get($key, $default);
            }

            return $data;
        }

        /**
         * @param \iterable $keys
         * @param null      $ttl
         *
         * @return boolean
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        function setMultiple($keys, $ttl = null) {
            $u = true;
            foreach ($keys as $key => &$value) {
                if (!$this->set($key, $value, $ttl)) {
                    $u = false;
                }
            }

            return $u;
        }

        /**
         * @param iterable $keys
         *
         * @return boolean
         * @throws \Psr\SimpleCache\InvalidArgumentException
         */
        function deleteMultiple($keys) {
            $u = true;
            foreach ($keys as &$key) {
                if (!$this->delete($key)) {
                    // @codeCoverageIgnoreStart
                    $u = false;
                    // @codeCoverageIgnoreEnd
                }
            }

            return $u;
        }

        /**
         * @param string $key
         *
         * @throws \Psr\SimpleCache\InvalidArgumentException
         * @return boolean
         */
        function has($key) {
            $this->is_key_valid($key);

            return $this->is_exist($key);
        }
    }

?>