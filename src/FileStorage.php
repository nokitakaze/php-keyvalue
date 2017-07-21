<?php

    namespace NokitaKaze\KeyValue;

    use NokitaKaze\Mutex\MutexInterface;
    use NokitaKaze\Mutex\FileMutex;

    /**
     * Class Key-value хранилище, использующее обыкновенные файлы
     */
    class FileStorage extends AbstractStorage {
        var $folder;
        protected $_multi_folder = false;
        const ERROR_CODE = 100;

        /**
         * @param KeyValueSettings|object $settings
         *
         * @throws KeyValueException
         */
        function __construct($settings) {
            if (!isset($settings->storage_type)) {
                $settings->storage_type = self::StorageTemporary;
            }
            switch ($settings->storage_type) {
                case self::StorageTemporary:
                    $this->folder = sys_get_temp_dir();
                    break;
                case self::StoragePersistent:
                    $this->folder = FileMutex::getDirectoryString();
                    break;
                default:
                    throw new KeyValueException('Constructor settings is malformed. Storage type can not be equal '.
                                                $settings->storage_type, self::ERROR_CODE + 1);
            }
            if (isset($settings->folder)) {
                $this->folder = $settings->folder;
            }
            if (isset($settings->multi_folder)) {
                $this->_multi_folder = (boolean) $settings->multi_folder;
            }

            $this->settings = $settings;
            $this->standard_prefix_strategy();
        }

        /**
         * @param string         $key   Название ключа
         * @param mixed          $value Новое значение
         * @param double|integer $ttl   Кол-во секунд, после которых значение будет считаться просроченным
         *
         * @throws KeyValueException
         */
        function set_value($key, $value, $ttl = 315576000) {
            $ts1 = microtime(true);
            $data = $this->form_datum_value($key, $value, $ttl);
            self::create_path($this->folder, $this->_multi_folder, $key);
            $this->get_lock($key);
            $temporary_filename = $this->get_filename($key).'.tmp';
            $result = @file_put_contents($temporary_filename, serialize($data), LOCK_EX);
            if ($result === false) {
                $this->release_lock();
                throw new KeyValueException('Can not save value', self::ERROR_CODE + 3);
            }
            chmod($temporary_filename, 6 << 6);
            if (!rename($temporary_filename, $this->get_filename($key))) {
                // @codeCoverageIgnoreStart
                @unlink($temporary_filename);
                $this->release_lock();
                throw new KeyValueException('Can not rename db file', self::ERROR_CODE + 4);
                // @codeCoverageIgnoreEnd
            }
            $this->release_lock();
            self::add_profiling(microtime(true) - $ts1, static::class, 'set_value');
        }

        /**
         * Создаём папку
         *
         * @param string  $folder
         * @param boolean $multi_folder
         * @param string  $key
         *
         * @throws KeyValueException
         */
        protected static function create_path($folder, $multi_folder, $key) {
            if (!file_exists($folder)) {
                if (!file_exists(dirname($folder))) {
                    throw new KeyValueException(sprintf('Folder %s does not exist', dirname($folder)),
                        self::ERROR_CODE + 5);
                } else {
                    @mkdir($folder);
                    @chmod($folder, (7 << 6) | (7 << 3));
                }
            }

            if (!$multi_folder) {
                return;
            }
            $hash = hash('sha512', $key);
            $folder .= '/'.substr($hash, 0, 2);
            if (!file_exists($folder)) {
                @mkdir($folder);
                @chmod($folder, (7 << 6) | (7 << 3));
            }
            $folder .= '/'.substr($hash, 2, 2);
            if (!file_exists($folder)) {
                @mkdir($folder);
                @chmod($folder, (7 << 6) | (7 << 3));
            }
        }

        /**
         * @param string $key
         *
         * @return object|null
         */
        protected function get_value_full_clear($key) {
            $filename = $this->get_filename($key);
            if (!file_exists($filename)) {
                return null;
            }
            $this->get_lock($key);
            $buf = @file_get_contents($filename);
            $this->release_lock();
            if ($buf === false) {
                return null;
            }
            $data = @unserialize($buf);
            if (($data === false) and ($buf != serialize(false))) {
                return null;
            }

            return $data;
        }

        /**
         * @param string $key
         *
         * @return string
         */
        function get_filename($key) {
            return $this->get_folder($key).'/ascetkey_'.$this->_prefix.hash('sha512', $key).'.dat';
        }

        /**
         * @param string $key
         *
         * @return string
         */
        function get_folder($key) {
            $full_folder = $this->folder;
            if ($this->_multi_folder) {
                $hash = hash('sha512', $key);
                $full_folder .= sprintf('/%s/%s', substr($hash, 0, 2), substr($hash, 2, 2));
            }

            return $full_folder;
        }

        /**
         * Key for NokitaKaze\Mutex\MutexInterface
         *
         * @param string $key
         *
         * @return string
         */
        function get_mutex_key_name($key) {
            // @hint Мы используем sha256, а не sha512, потому что иначе у нас просто не хватит длины
            return 'ascetkey_'.hash('sha256', $this->get_filename($key));
        }

        /**
         * Создаём мьютекс, соответствующий ключу и кладём его в _locks
         *
         * @param string $key
         */
        protected function create_lock($key) {
            // @todo Любой мьютекс
            $this->_locks[$key] = new FileMutex([
                'name' => $this->get_mutex_key_name($key),
                'prefix' => '',
                'folder' => $this->get_folder($key),
            ]);
        }

        /**
         * @param string $key
         */
        function delete_value($key) {
            $ts1 = microtime(true);
            $filename = $this->get_filename($key);
            if (!file_exists($filename)) {
                self::add_profiling(microtime(true) - $ts1, static::class, 'delete_value');

                return;
            }
            $this->get_lock($key);
            @unlink($filename);
            $this->release_lock();
            self::add_profiling(microtime(true) - $ts1, static::class, 'delete_value');
        }

        /**
         * Санация части regexp для добавления напрямую в regexp
         *
         * @param string $text Часть, которую над санировать
         *
         * @return string
         * @codeCoverageIgnore
         */
        protected static function sad_safe_reg($text) {
            $ar = '.-\\/[]{}()*?+^$|';
            $s = '';
            for ($i = 0; $i < strlen($text); $i++) {
                if (strpos($ar, $text[$i]) !== false) {
                    $s .= '\\'.$text[$i];
                } else {
                    $s .= $text[$i];
                }
            }

            return $s;
        }

        /**
         * @param string $folder
         */
        protected function delete_all_files_in_folder($folder) {
            foreach (scandir($folder) as $d) {
                if (!in_array($d, ['.', '..']) and
                    preg_match('|^ascetkey_'.self::sad_safe_reg($this->_prefix).'[a-f0-9]{128,128}\\.dat$|', $d)
                ) {
                    unlink($folder.'/'.$d);
                }
            }
        }

        function clear() {
            if (!file_exists($this->folder) or !is_dir($this->folder)) {
                return;
            }
            if (!$this->_multi_folder) {
                $this->delete_all_files_in_folder($this->folder);
            } else {
                for ($i = 0; $i < 256; $i++) {
                    // @todo заменить на pack
                    if ($i < 16) {
                        $k1 = '0'.dechex($i);
                    } else {
                        $k1 = dechex($i);
                    }
                    if (!file_exists($this->folder.'/'.$k1) or !is_dir($this->folder.'/'.$k1)) {
                        continue;
                    }

                    for ($j = 0; $j < 256; $j++) {
                        // @todo заменить на pack
                        if ($j < 16) {
                            $k2 = '0'.dechex($j);
                        } else {
                            $k2 = dechex($j);
                        }

                        $full_folder = $this->folder.'/'.$k1.'/'.$k2;
                        if (file_exists($full_folder) and is_dir($full_folder)) {
                            $this->delete_all_files_in_folder($full_folder);
                        }
                    }
                }
            }
        }
    }

?>