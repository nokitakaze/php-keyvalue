<?php

    namespace NokitaKaze\KeyValue;

    require_once __DIR__.'/KVAbstractTest.php';

    class RedisStorageMockTest extends KVAbstractTest {
        private static $_test_server_post = null;

        private static $_general_failure = false;

        private $_folder;

        function setUp() {
            parent::setUp();
            $this->_folder = self::get_tmp_dir().'/nkt_test_'.self::generate_hash();
            mkdir($this->_folder);
            if (self::$_general_failure) {
                $this->fail('Can not open Redis');
            }
        }

        function tearDown() {
            chmod($this->_folder, 7 << 6);
            system(sprintf('rm -rf %s', escapeshellarg($this->_folder)));
            parent::tearDown();
        }

        protected static function get_class() {
            return 'NokitaKaze\\KeyValue\\RedisStorage';
        }

        protected static function get_class_overloaded() {
            return 'NokitaKaze\\KeyValue\\RedisStorageMockTestOverload';
        }

        static function setUpBeforeClass() {
            parent::setUpBeforeClass();
            self::$_test_server_post = mt_rand(60000, 63000);
            exec(sprintf('php %s/TestRedisServer.php -p %d -w 300 >/dev/null 2>&1 &', __DIR__, self::$_test_server_post));
            if ((posix_uname()['sysname'] == 'Linux') and false) {// @todo and not travis
                $u = false;
                for ($i = 0; $i < 50; $i++) {
                    exec(sprintf('netstat -n -t -l | grep :%d ', self::$_test_server_post), $buf);
                    if (strlen(implode("\n", $buf)) >= 5) {
                        $u = true;
                        break;
                    }
                    usleep(100000);
                }
                if (!$u) {
                    self::$_general_failure = true;
                    self::fail(sprintf('Can not set up Redis on %d', self::$_test_server_post));
                }
            } else {
                sleep(5);
            }
        }

        static function tearDownAfterClass() {
            try {
                self::get_redis()->redis_send("shutdown");
            } catch (KeyValueException $e) {
            }
            parent::tearDownAfterClass();
        }

        private static function set_test_server_setting($settings) {
            file_put_contents(__DIR__.'/redis_server.dat', json_encode((object) $settings));
        }

        function testRedis_recv() {
            $kv = self::get_redis(['timeout' => 2]);
            $reflection = new \ReflectionMethod($kv, 'lazy_init');
            $reflection->setAccessible(true);
            $reflection->invoke($kv);
            $reflection2 = new \ReflectionMethod($kv, 'redis_recv');
            $reflection2->setAccessible(true);
            $reflection_settings = new \ReflectionProperty($kv, 'settings');
            $reflection_settings->setAccessible(true);
            $u = false;
            /**
             * @var double $ts1
             * @var double $ts2
             */
            $ts1 = microtime(true);
            try {
                $reflection2->invoke($kv);
            } catch (KeyValueException $e) {
                $ts2 = microtime(true);
                $code1 = $e->getCode();
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
                $u = true;
            }
            if (!$u) {
                $this->fail('RedisStorage::redis_recv does not throw Exception on timeout');
            }
            $this->assertGreaterThan(2, $ts2 - $ts1);
            //$this->assertLessThan(2.2, $ts2 - $ts1);// @hint Это бенчмарк
            //
            $kv->redis_send('info');
            $reflection_socket = new \ReflectionProperty($kv, '_socket');
            $reflection_socket->setAccessible(true);
            socket_shutdown($reflection_socket->getValue($kv));
            socket_close($reflection_socket->getValue($kv));
            try {
                $reflection2->invoke($kv);
            } catch (KeyValueException $e) {
                $code2 = $e->getCode();
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
                $u = true;
            }
            if (!$u) {
                $this->fail('RedisStorage::redis_recv does not throw Exception on closed socket');
            }
            $this->assertGreaterThan(2, $ts2 - $ts1);
            //$this->assertLessThan(2.2, $ts2 - $ts1);// @hint Это бенчмарк
            /** @noinspection PhpUndefinedVariableInspection */
            $this->assertNotEquals($code1, $code2);
            // Тестируем mode=0 с огромным чанком
            self::set_test_server_setting(['mode' => 'info_big_chunk']);
            $kv = self::get_redis();
            $kv->redis_send('info');
            $this->assertRegExp('_^Server:\r\n[a-zA-Z0-9]{1000,}(\r\n)?$_', $reflection2->invoke($kv, 0));
            //
            self::set_test_server_setting(['mode' => 'info_wait_before_crlf']);
            $kv = self::get_redis();
            $kv->redis_send('info');
            $this->assertNotFalse(strpos($reflection2->invoke($kv, 1), "\r\n"));
            //
            self::set_test_server_setting(['mode' => 'info_wait_before_crlf2']);
            $kv = self::get_redis();
            $kv->redis_send('info');
            $this->assertNotFalse(strpos($reflection2->invoke($kv, 1), "\r\n"));
            //
            $u = false;
            try {
                self::set_test_server_setting(['mode' => 'info_wait_before_crlf_timeout10']);
                $kv = self::get_redis();
                $kv->redis_send('info');
                $ts1 = microtime(true);
                $reflection_settings->setValue($kv, (object) ['timeout' => 3]);
                $reflection2->invoke($kv, 1);
            } catch (KeyValueException $e) {
                $ts2 = microtime(true);
                $this->assertRegExp('_timeout_i', $e->getMessage());
                $this->assertGreaterThan(3, $ts2 - $ts1);
                //$this->assertLessThan(3 + 10, $ts2 - $ts1);// @hint Это бенчмарк
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
                $u = true;
            }
            if (!$u) {
                $this->fail('RedisStorage::redis_recv didn\'t throw Exception on waiting for crlf');
            }
        }

        /**
         * @param object            $codes
         * @param \ReflectionMethod $reflection
         * @param \ReflectionMethod $reflection_release
         * @param string            $mode
         *
         * @dataProvider dataSet_value
         */
        function testSet_value($codes, $reflection, $reflection_release, $mode) {
            self::set_test_server_setting(['mode' => $mode]);
            $kv = self::get_redis();
            $u = false;
            $last_code = null;
            try {
                $reflection->invoke($kv, 'nyan', 'pasu', 60);
            } catch (KeyValueException $e) {
                $last_code = $e->getCode();
                $this->assertNotNull($last_code);
                $this->assertGreaterThan(0, $last_code);
                $this->assertNotContains($last_code, $codes->list);
                $codes->list[] = $last_code;
                $u = true;
            }
            if (!$u) {
                $this->fail('RedisStorage::set_value_low_level didn\'t throw Exception on mode '.$mode);
            }
            // Теперь через враппер
            $reflection_release->invoke($kv);
            $kv = self::get_redis();
            try {
                $kv->set_value('nyan', 'pasu', 60);
            } catch (KeyValueException $e) {
                $this->assertEquals($last_code, $e->getCode());
                $u = true;
            }
            if (!$u) {
                $this->fail('RedisStorage::set_value didn\'t throw Exception on mode '.$mode);
            }
        }

        /**
         *
         */
        function dataSet_value() {
            $codes = (object) ['list' => []];
            $reflection = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\RedisStorage', 'set_value_low_level');
            $reflection->setAccessible(true);
            $reflection_release = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\RedisStorage', 'release_lock');
            $reflection_release->setAccessible(true);
            $data = [];
            foreach (['multi_malformed', 'hmset_error', 'set_error2', 'expireat_malformed', 'expireat_malformed2',
                      'exec_malformed'] as &$mode) {
                $data[] = [$codes, $reflection, $reflection_release, $mode];
            }

            return $data;
        }

        function testDelete_value() {
            self::set_test_server_setting(['mode' => '']);
            $kv = self::get_redis();
            $kv->delete_value('key');
            //
            self::set_test_server_setting(['mode' => 'del_error']);
            $kv = self::get_redis();
            $u = false;
            try {
                $kv->delete_value('key');
            } catch (KeyValueException $e) {
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
                $u = true;
            }
            if (!$u) {
                $this->fail('RedisStorage::delete_value didn\'t throw Exception on delete error');
            }
        }

        // @todo delete с падением Redis'а: проверять, что блокировка снимается

        /**
         * Время просрочки верное
         *
         * @return bool
         */
        static function time_expires_accurate() {
            return false;
        }

        /**
         * Время создания/обновление записи верное
         *
         * @return bool
         */
        static function time_create_accurate() {
            return false;
        }

        /**
         * @param array $params
         *
         * @return object
         */
        protected function get_full_params($params) {
            return (object) ['timeout' => 60, 'port' => self::$_test_server_post];
        }

        /**
         * @var array $params
         *
         * @return RedisStorage
         */
        protected function get_kv_storage($params) {
            return self::get_redis($params);
        }

        /**
         * @param array $options
         *
         * @return RedisStorage
         */
        private static function get_redis($options = []) {
            $full_options = ['timeout' => 60, 'port' => self::$_test_server_post];
            foreach ($options as $key => $value) {
                $full_options[$key] = $value;
            }

            return new RedisStorage((object) $full_options);
        }

        function testGet_value_full_clear_low_level() {
            $reflection = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\RedisStorage', 'get_value_full_clear_low_level');
            $reflection->setAccessible(true);
            $reflection2 = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\RedisStorage', 'get_value_full_clear');
            $reflection2->setAccessible(true);
            $keys = ['malformed_hgetall', 'hgetall_malformed_123',];
            $codes = [];
            foreach ($keys as &$mode) {
                self::set_test_server_setting(['mode' => $mode]);
                $kv = self::get_redis();
                $u = false;
                try {
                    $reflection->invoke($kv, 'nyanpasu');
                } catch (KeyValueException $e) {
                    if (!preg_match('_timeout_i', $e->getMessage())) {
                        $codes[] = $e->getCode();
                        $u = true;
                    }
                    $this->assertNotNull($e->getCode());
                    $this->assertGreaterThan(0, $e->getCode());
                }
                if (!$u) {
                    $this->fail('RedisStorage::get_value_full_clear_low_level didn\'t throw Exception on '.$mode);
                }
                $this->assertNull($reflection2->invoke($kv, 'nyanpasu'));
            }
            $this->assertGreaterThanOrEqual(2, count(array_unique($codes)));
            foreach (['hgetall_long_answer', 'hgetall_long_answer2'] as $mode) {
                self::set_test_server_setting(['mode' => $mode]);
                $kv = self::get_redis();
                $this->assertNotNull($reflection->invoke($kv, 'nyanpasu'));
            }
            //
            $kv = self::get_redis();
            self::set_test_server_setting(['mode' => 'empty_value',]);
            $reflection->invoke($kv, 'nyanpasu');// Здесь поведение не определено
            //
            $kv = self::get_redis();
            self::set_test_server_setting(['mode' => 'get_non64',]);
            $this->assertNull($reflection->invoke($kv, 'nyanpasu'));
            //
            $kv = self::get_redis();
            self::set_test_server_setting(['mode' => 'get_nongzip',]);
            $this->assertNull($reflection->invoke($kv, 'nyanpasu'));
            // @todo добавить  'loading'
            //
            $kv = self::get_redis();
            self::set_test_server_setting(['mode' => 'long_answer',]);
            $this->assertNull($reflection->invoke($kv, 'nyanpasu'));
            //
            $kv = self::get_redis();
            self::set_test_server_setting(['mode' => 'normal',]);
            $this->assertNotNull($reflection->invoke($kv, 'nyanpasu'));
        }

        function testGet_expires_time_suite3() {
            self::set_test_server_setting(['mode' => 'ttl_malformed']);
            $kv = self::get_redis();
            $this->assertNull($kv->get_expires_time('nyan'));
            self::set_test_server_setting(['mode' => 'ttl_minus']);
            $kv = self::get_redis();
            $this->assertNull($kv->get_expires_time('nyan'));
        }

        function testSet_expires_time() {
            $codes = [];
            foreach (['multi_malformed', 'expireat_malformed', 'expireat_malformed2', 'exec_malformed'] as &$mode) {
                self::set_test_server_setting(['mode' => $mode]);
                $kv = self::get_redis();
                $u = false;
                try {
                    $kv->set_expires_time('nyan', 60);
                } catch (KeyValueException $e) {
                    $this->assertNotContains($e->getCode(), $codes);
                    $codes[] = $e->getCode();
                    $this->assertNotNull($e->getCode());
                    $this->assertGreaterThan(0, $e->getCode());
                    $u = true;
                }
                if (!$u) {
                    $this->fail('RedisStorage::set_expires_time didn\'t throw Exception on mode '.$mode);
                }
            }
            //
            self::set_test_server_setting(['mode' => 'exec_commit_set_expire']);
            $kv = self::get_redis();
            $kv->set_expires_time('nyan', 60);
            //
            self::set_test_server_setting(['mode' => 'exec_commit_set_expire_pause']);
            $kv = self::get_redis();
            $kv->set_expires_time('nyan', 60);
        }

        function testFormat_error() {
            $data = [
                [['nyan', ''], 'nyan'],
                [['nyan', '-ERR pasu'], 'nyan: pasu'],
                [['nyan', '-ERR  pasu'], 'nyan: pasu'],
                [['nyan', '-ERR pasu '], 'nyan: pasu'],
                [['nyan', '-ERR  pasu '], 'nyan: pasu'],
                [['nyan', ' -ERR  pasu '], 'nyan'],
                [['nyan', ' -ERR '], 'nyan'],
                [['nyan', '-PASU FOOBAR'], 'nyan: PASU FOOBAR'],
            ];
            foreach ($data as &$datum) {
                $ret = RedisStorage::format_error($datum[0][0], $datum[0][1]);
                $this->assertEquals($ret, $datum[1]);
            }
        }

        function testDelete_value_suite2() {
            return;
        }

        function testGet_lockBehavior() {
            return;
        }

        function dataGet_expires_time() {
            return [[null]];
        }

        /**
         * @param boolean $u
         *
         * @dataProvider dataGet_expires_time
         */
        function testGet_expires_time_suite1($u) {
            return;
        }

        /**
         * @param array   $params
         * @param integer $count
         *
         * @dataProvider dataMultiValue
         * @large
         */
        function testMultiValue(array $params, $count) {
            // My Mock server can not swallow this thing
            return;
        }

        function dataMultiple() {
            return [[0, 0]];
        }

        /**
         * @param integer $type1
         * @param integer $type2
         *
         * @throws \Exception
         * @dataProvider dataMultiple
         */
        function testMultiple($type1, $type2) { }

        protected function clear_test(AbstractStorage $kv, $count) { }
    }

?>