<?php

    namespace NokitaKaze\KeyValue;

    use NokitaKaze\Mutex\MutexInterface;
    use NokitaKaze\Mutex\FileMutex;

    /**
     * Class Key-value хранилище, использующее Redis
     */
    class RedisStorage extends AbstractStorage {
        const REDIS_RECV_CHUNK_SIZE = 4096;
        const REDIS_TIMEOUT_LIMIT = 30;
        const KeyPrefix = 'AscetKeyValue';
        const REDIS_STRING_CHUNK_SIZE = 30 * 1024;

        /**
         * @var resource|null
         */
        protected $_socket = null;
        const ERROR_CODE = 400;

        protected $_mutex_root_folder = null;

        function __construct($settings) {
            $default_settings = [
                'database' => 0,
                'storage_type' => self::StoragePersistent,
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => self::REDIS_TIMEOUT_LIMIT,
                'string_chunk_size' => self::REDIS_STRING_CHUNK_SIZE,
            ];
            foreach ($default_settings as $key => &$default_value) {
                if (!isset($settings->{$key})) {
                    $settings->{$key} = $default_value;
                }
            }

            $this->settings = $settings;
            $this->standard_prefix_strategy();
            if (isset($settings->folder)) {
                $this->_mutex_root_folder = $settings->folder;
            }
        }

        function __destruct() {
            if (!is_null($this->_socket)) {
                if (@socket_shutdown($this->_socket)) {
                    @socket_close($this->_socket);
                }
            }
        }

        /**
         * @throws KeyValueException
         */
        protected function lazy_init() {
            if (!is_null($this->_socket)) {
                return;
            }
            $this->_socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($this->_socket === false) {
                // @codeCoverageIgnoreStart
                throw new KeyValueException('Can not create socket', self::ERROR_CODE + 0);
                // @codeCoverageIgnoreEnd
            }
            $u = @socket_connect($this->_socket, $this->settings->host, $this->settings->port);
            if (!$u) {
                throw new KeyValueException('Can not connect to '.$this->settings->host.':'.$this->settings->port,
                    self::ERROR_CODE + 1);
            }
            if ($this->settings->database != 0) {
                $this->redis_send(sprintf('select %d', $this->settings->database));
                $redis_answer = $this->redis_recv(1);
                if ($redis_answer != "+OK\r\n") {
                    throw new KeyValueException(self::format_error('Can not select database '.$this->settings->database,
                        $redis_answer), self::ERROR_CODE + 3);
                }
            }
        }

        /**
         * @param string $command
         *
         * @throws KeyValueException
         */
        function redis_send($command) {
            $this->lazy_init();
            $command .= "\n";
            $i = @socket_write($this->_socket, $command, strlen($command));
            if (($i === false) or ($i != strlen($command))) {
                throw new KeyValueException('Can not send command', self::ERROR_CODE + 2);
            }
        }

        /**
         * Получаем данные из соккета
         *
         * Этот метод не делает lazy_init, потому что его делает redis_send, а redis_recv обязан (must) быть вызван
         * только после redis_send
         *
         * @param integer $mode (0 — набрать на блок; 1 — до crlf; 2 — получить как можно быстрее)
         *
         * @return string
         * @throws KeyValueException
         */
        protected function redis_recv($mode = 1) {
            $buf = '';
            $r = [$this->_socket];
            $o = null;
            do {
                $count = @socket_select($r, $o, $o, (($mode !== 2) or ($buf == '')) ? $this->settings->timeout : 0);
                if ($count === false) {
                    throw new KeyValueException('Redis server read error', self::ERROR_CODE + 16);
                } elseif (($count === 0) and ($mode !== 2)) {
                    throw new KeyValueException('Redis server timeout', self::ERROR_CODE + 10);
                } elseif ($count === 0) {
                    return $buf;
                }
                $i = @socket_recv($this->_socket, $buf_chunk, self::REDIS_RECV_CHUNK_SIZE, 0);
                if ($i === false) {
                    // @codeCoverageIgnoreStart
                    throw new KeyValueException('Can not read from redis server', self::ERROR_CODE + 4);
                    // @codeCoverageIgnoreEnd
                }
                $buf .= $buf_chunk;
                if (($mode === 1) and (strpos($buf, "\r\n") !== false)) {
                    return $buf;
                }
            } while ((($mode === 0) and (strlen($buf) < self::REDIS_RECV_CHUNK_SIZE)) or in_array($mode, [1, 2]));

            return $buf;
        }

        /**
         * @param string $key
         *
         * @return string
         */
        protected function get_full_key_name($key) {
            return sprintf('%s:%s:%s', self::KeyPrefix, $this->_prefix, $key);
        }

        /**
         * @param string $key
         *
         * @return object|null
         */
        protected function get_value_full_clear($key) {
            try {
                $this->get_lock($key);
                $value = $this->get_value_full_clear_low_level($key);
                $this->release_lock();

                return $value;
            } catch (KeyValueException $e) {
                $this->release_lock();

                return null;
            }
        }

        /**
         * @param string $key
         *
         * @return object|null
         * @throws KeyValueException
         */
        protected function get_value_full_clear_low_level($key) {
            $this->redis_send(sprintf('hgetall %s', $this->get_full_key_name($key)));
            // @todo -LOADING Redis is loading the dataset in memory
            $recv_text = $this->redis_recv(1);
            if ($recv_text == "*0\r\n") {
                return null;
            }
            if (substr($recv_text, 0, 1) != '*') {
                throw new KeyValueException(self::format_error('Redis sent malformed response', $recv_text),
                    self::ERROR_CODE + 11);
            }
            list($num, $strings) = $this->get_hget_from_redis($recv_text);
            $data = self::format_object_from_raw_strings($strings, $num);
            unset($strings, $num, $recv_text);
            $data->time_create = isset($data->time_create) ? (double) $data->time_create : null;
            $data->time_expires = $this->get_expires_time($key);
            $data->init_line = isset($data->init_line) ? (int) $data->init_line : null;
            // Get
            $this->redis_send(sprintf('get %s:value', $this->get_full_key_name($key)));
            // @todo -LOADING Redis is loading the dataset in memory
            $recv_text = $this->redis_recv(1);
            if (!preg_match('_^\\$[0-9]+\\r\\n_s', $recv_text, $a)) {
                $data->value = null;

                return $data;
            }
            unset($a);
            list($len, $recv_text) = explode("\r\n", substr($recv_text, 1), 2);
            $len = (int) $len;
            while (strlen($recv_text) < $len + 2) {
                $recv_text .= $this->redis_recv(2);
            }

            $def = @gzinflate(base64_decode(substr($recv_text, 0, strlen($recv_text) - 2)));
            if ($def === false) {
                return null;
            }
            $data->value = unserialize($def);

            return $data;
        }

        /**
         * Получаем всё из hgetall
         *
         * Метод считает, что запрос уже сделан
         *
         * @param string $recv_text Заранее готовый текст, который уже был получен из redis'а
         *
         * @return string[][]|null[][]|integer[]
         * @throws KeyValueException
         */
        protected function get_hget_from_redis($recv_text) {
            list($num, $recv_text) = explode("\r\n", substr($recv_text, 1), 2);
            $num = (int) $num;

            $strings = [];
            for ($value_i = 0; $value_i < $num; $value_i++) {
                while (!preg_match('_^(.*?)\\r\\n_', $recv_text, $a)) {
                    $recv_text .= $this->redis_recv(2);
                }
                if (!preg_match('_^\\$([0-9]+)\\r\\n(.*)$_s', $recv_text, $a)) {
                    throw new KeyValueException(self::format_error('Redis sent malformed response', $recv_text),
                        self::ERROR_CODE + 12);
                }

                $len = (int) $a[1];
                $need_length = 3 + strlen($a[1]) + $len + 2;
                while ($need_length > strlen($recv_text)) {
                    $recv_text .= $this->redis_recv(2);
                }
                $strings[] = substr($recv_text, 3 + strlen($a[1]), $len);
                $recv_text = substr($recv_text, $need_length);
            }

            return [$num, $strings];
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
        function set_value($key, $value, $ttl = 315576000) {
            $ts1 = microtime(true);
            try {
                $this->get_lock($key);
                $this->set_value_low_level($key, $value, $ttl);
                $this->release_lock();
                self::add_profiling(microtime(true) - $ts1, static::class, 'set_value');
            } catch (KeyValueException $e) {
                $this->release_lock();
                self::add_profiling(microtime(true) - $ts1, static::class, 'set_value');
                throw $e;
            }
        }

        /**
         * Функция, непосредственно записывающая новое значение. Она не освобождает мьютексы
         *
         * @param string         $key   Название ключа
         * @param mixed          $value Новое значение
         * @param double|integer $ttl   Кол-во секунд, после которых значение будет считаться просроченным
         *
         * @throws KeyValueException
         */
        protected function set_value_low_level($key, $value, $ttl) {
            $values = $this->form_datum_value($key, null, $ttl);
            $time_expires = $values->time_expires;
            unset($values->value, $values->time_expires);

            $texts = self::format_hmset_arguments($values);
            unset($values);
            $this->get_lock($key);
            $this->redis_send('multi');
            $redis_answer = $this->redis_recv(1);
            if ($redis_answer != "+OK\r\n") {
                throw new KeyValueException(self::format_error('Can not start transaction', $redis_answer), self::ERROR_CODE + 5);
            }
            $full_key = $this->get_full_key_name($key);
            // HMSet
            $text = sprintf('hmset %s %s', $full_key, implode(' ', $texts));
            $this->redis_send($text);
            $redis_answer = $this->redis_recv(1);
            if (!in_array($redis_answer, ["+OK\r\n", "+QUEUED\r\n"])) {
                throw new KeyValueException(self::format_error('Can not set meta info', $redis_answer), self::ERROR_CODE + 6);
            }
            unset($texts, $text);
            // Ставим expires
            $this->redis_send(sprintf('expireat %s %d', $full_key, ceil($time_expires)));
            $redis_answer = $this->redis_recv(1);
            if (!in_array($redis_answer, ["+OK\r\n", "+QUEUED\r\n"])) {
                throw new KeyValueException(self::format_error('Can not set expires time', $redis_answer), self::ERROR_CODE + 7);
            }
            $full_value_string = base64_encode(gzdeflate(serialize($value)));
            $u = false;
            while (strlen($full_value_string) > 0) {
                $this->redis_send(sprintf('%s %s:value "%s"',
                    $u ? 'append' : 'set',
                    $full_key,
                    substr($full_value_string, 0, $this->settings->string_chunk_size)));
                $redis_answer = $this->redis_recv(1);
                if (!in_array($redis_answer, ["+OK\r\n", "+QUEUED\r\n"])) {
                    throw new KeyValueException(self::format_error('Can not set value', $redis_answer), self::ERROR_CODE + 17);
                }
                $u = true;

                $full_value_string = substr($full_value_string, $this->settings->string_chunk_size);
            }
            $this->redis_send(sprintf('expireat %s:value %d', $full_key, ceil($time_expires)));
            $redis_answer = $this->redis_recv(1);
            if (!in_array($redis_answer, ["+OK\r\n", "+QUEUED\r\n"])) {
                throw new KeyValueException(self::format_error('Can not set expires time', $redis_answer), self::ERROR_CODE + 18);
            }

            // Коммитим
            $this->redis_send('exec');
            $commit_text = $this->redis_recv(1);
            if (!preg_match('_^\\*[0-9]+\\r\\n\\+OK\\r\\n_i', $commit_text)) {
                $this->redis_send('discard');
                throw new KeyValueException(self::format_error('Can not commit transaction', $commit_text), self::ERROR_CODE + 8);
            }
        }

        /**
         * @param object $values
         *
         * @return string[]
         */
        static function format_hmset_arguments($values) {
            $texts = [];
            foreach ($values as $v_k => &$v_v) {
                if (is_null($v_v) or ($v_v === '')) {
                    $texts[] = sprintf('%s " "', $v_k);// @todo Это DAT КОСТЫЛЬ, потому что не знаю как передать empty string
                } else {
                    $texts[] = sprintf('%s "%s"', $v_k, self::str_sanify($v_v));
                }
            }

            return $texts;
        }

        /**
         * Форматируем объект из набора сырых строк, переданных из Redis'а
         *
         * @param string[] $strings
         * @param integer  $num
         *
         * @return object
         */
        protected static function format_object_from_raw_strings($strings, $num) {
            $data = new \stdClass();
            for ($value_i = 0; $value_i < $num; $value_i += 2) {
                $value = $strings[$value_i + 1];
                if ($value == ' ') {// @todo DAT КОСТЫЛЬ, потому что не знаю как передать empty string
                    $value = '';
                }
                $data->{$strings[$value_i]} = $value;
            }

            return $data;
        }

        /**
         * @param string $key
         *
         * @throws KeyValueException
         */
        function delete_value($key) {
            $ts1 = microtime(true);
            try {
                $this->get_lock($key);
                $this->delete_value_low_level($key);
                $this->release_lock();
                self::add_profiling(microtime(true) - $ts1, static::class, 'delete_value');
            } catch (KeyValueException $e) {
                $this->release_lock();
                self::add_profiling(microtime(true) - $ts1, static::class, 'delete_value');
                throw $e;
            }
        }

        /**
         * @param string $key
         *
         * @throws KeyValueException
         */
        protected function delete_value_low_level($key) {
            foreach ([$this->get_full_key_name($key), $this->get_full_key_name($key).':value'] as $full_key) {
                $this->redis_send(sprintf('del %s', $full_key));
                $commit_text = $this->redis_recv(1);
                if (!preg_match('_^\\:[0-9]+\\r\\n_', $commit_text)) {
                    throw new KeyValueException(self::format_error('Can not delete key', $commit_text),
                        self::ERROR_CODE + 9);
                }
            }
        }

        // @todo Имплементировать get_change_time

        /**
         * Время просрочки значения, если есть
         *
         * @param string $key
         *
         * @return double|null
         */
        function get_expires_time($key) {
            $this->redis_send(sprintf('ttl %s', $this->get_full_key_name($key)));
            $commit_text = $this->redis_recv(1);
            if (!preg_match('_^\\:(\\-?[0-9]+)_', $commit_text, $a)) {
                return null;
            }
            if ($a[1] < 0) {
                return null;
            } else {
                return time() + $a[1];
            }
        }

        /**
         * Обновляем срок жизни записи
         *
         * @param string $key Название ключа
         * @param double $ttl Кол-во секунд, после которых значение будет считаться просроченным
         *
         * @throws KeyValueException
         */
        function set_expires_time($key, $ttl) {
            $current_ttl = $this->get_expires_time($key);
            if (is_null($current_ttl)) {
                // Нет такого объекта
                return;
            }

            $this->redis_send('multi');
            $redis_answer = $this->redis_recv(1);
            if ($redis_answer != "+OK\r\n") {
                throw new KeyValueException(self::format_error('Can not start transaction', $redis_answer),
                    self::ERROR_CODE + 13);
            }
            $full_key = $this->get_full_key_name($key);
            // Ставим expires
            $this->redis_send(sprintf('expireat %s %d', $full_key, ceil(time() + $ttl)));
            $redis_answer = $this->redis_recv(1);
            if (!in_array($redis_answer, ["+OK\r\n", "+QUEUED\r\n"])) {
                throw new KeyValueException(self::format_error('Can not set expires time', $redis_answer), self::ERROR_CODE + 14);
            }
            $this->redis_send(sprintf('expireat %s:value %d', $full_key, ceil(time() + $ttl)));
            $redis_answer = $this->redis_recv(1);
            if (!in_array($redis_answer, ["+OK\r\n", "+QUEUED\r\n"])) {
                throw new KeyValueException(self::format_error('Can not set expires time', $redis_answer), self::ERROR_CODE + 15);
            }

            // Коммитим
            $this->redis_send('exec');
            $need_string = "*2\r\n:1\r\n:1\r\n";
            $commit_text = '';
            do {
                $commit_text .= $this->redis_recv(1);
            } while (strlen($commit_text) < strlen($need_string));
            if ($commit_text != $need_string) {
                $this->redis_send('discard');
                throw new KeyValueException(self::format_error('Can not commit transaction', $commit_text),
                    self::ERROR_CODE + 17);
            }
        }

        /**
         * @param string $error
         * @param string $text
         *
         * @return string string
         */
        static function format_error($error, $text) {
            if (preg_match('_^\\-ERR\\s+(.+?)\\s*$_mi', $text, $a)) {
                return sprintf('%s: %s', $error, $a[1]);
            } elseif (preg_match('_^\\-\\s*(.+?)\\s*$_mi', $text, $a)) {
                return sprintf('%s: %s', $error, $a[1]);
            } else {
                return $error;
            }
        }

        static function str_sanify($text) {
            return str_replace([
                '\\',
                "'",
                '"',
                "\r",
                "\n",
            ], [
                '\\\\',
                "\\'",
                '\\"',
                "\\r",
                "\\n",
            ], $text);
        }

        /**
         * Создаём мьютекс, соответствующий ключу и кладём его в _locks
         *
         * @param string $key
         */
        protected function create_lock($key) {
            if ($this->_mutex_root_folder == null) {
                $folder = null;
            } elseif ($this->settings->multi_folder_mutex) {
                $hash = hash('sha512', $key);
                $folder = $this->_mutex_root_folder.sprintf('/%s/%s', substr($hash, 0, 2), substr($hash, 2, 2));
                if (!file_exists($this->_mutex_root_folder.'/'.substr($hash, 0, 2))) {
                    mkdir($this->_mutex_root_folder.'/'.substr($hash, 0, 2));
                }
                if (!file_exists($folder)) {
                    mkdir($folder);
                }
            } else {
                $folder = $this->_mutex_root_folder;
            }

            // @todo Любой мьютекс
            $this->_locks[$key] = new FileMutex([
                'name' => 'ascetkey_'.$key,
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
        }

        /**
         * @throws KeyValueException
         */
        function clear() {
            $this->redis_send(sprintf('keys %s', $this->get_full_key_name('*')));
            $commit_text = $this->redis_recv(2);
            if (!preg_match('_^\\*([0-9]+)\\r\\n_', $commit_text, $a)) {
                throw new KeyValueException(self::format_error('Can not delete keys', $commit_text),
                    self::ERROR_CODE + 20);
            }
            $count = (int) $a[1];

            $strings = explode("\r\n", $commit_text);
            $list = [];
            for ($offset = 2; $offset <= 1 + 2 * $count; $offset += 2) {
                $list[] = $strings[$offset];
            }
            unset($offset, $count, $strings, $commit_text, $a);

            foreach (array_chunk($list, 20) as &$sub_list) {
                $this->redis_send(sprintf('del %s', implode(' ', $sub_list)));
                $commit_text = $this->redis_recv(1);
                if (!preg_match('_^\\:([0-9]+)_', $commit_text, $a)) {
                    throw new KeyValueException('Can not delete keys while doing "clear"');
                }
            }
        }
    }

?>