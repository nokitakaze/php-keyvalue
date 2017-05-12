<?php
    namespace NokitaKaze\KeyValue;

    require_once __DIR__.'/KVAbstractTest.php';

    class RedisStorageRealTest extends KVAbstractTest {
        private $_folder;

        function setUp() {
            parent::setUp();
            $this->_folder = self::get_tmp_dir().'/nkt_test_'.self::generate_hash();
            mkdir($this->_folder);
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
            return 'NokitaKaze\\KeyValue\\RedisStorageRealTestOverload';
        }

        /**
         * @backupGlobals
         */
        function testFolder_real() {
            foreach ([false, true] as $u) {
                foreach ([$this->_folder, null] as $folder) {
                    $this->set_value_sub_low_level(['folder' => $folder, 'multi_folder_mutex' => $u,]);
                }
            }
        }

        /**
         * @covers \NokitaKaze\KeyValue\RedisStorage::lazy_init
         */
        function testLazy_init_real() {
            $kv = new RedisStorage((object) ['port' => mt_rand(63001, 65534)]);
            $u = false;
            //
            $reflection = new \ReflectionMethod($kv, 'lazy_init');
            $reflection->setAccessible(true);

            try {
                $reflection->invoke($kv);
            } catch (KeyValueException $e) {
                $code1 = $e->getCode();
                $u = true;
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
            }
            if (!$u) {
                $this->fail('RedisStorage does not throw Exception on closed port');
            }
            //
            $kv = $this->get_kv_storage(['database' => mt_rand(1000, 10000)]);
            $u = false;
            try {
                $reflection->invoke($kv);
            } catch (KeyValueException $e) {
                /** @noinspection PhpUndefinedVariableInspection */
                $this->assertNotEquals($code1, $e->getCode());
                $u = true;
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
            }
            if (!$u) {
                $this->fail('RedisStorage does not throw Exception on malformed database');
            }
            //
            $kv = $this->get_kv_storage(['database' => 1]);
            $reflection->invoke($kv);
        }

        /**
         * @covers \NokitaKaze\KeyValue\RedisStorage::redis_send
         */
        function testRedis_send_real() {
            $kv = $this->get_kv_storage([]);
            $u = false;
            $reflection_socket = new \ReflectionProperty($kv, '_socket');
            $reflection_socket->setAccessible(true);
            $reflection = new \ReflectionMethod($kv, 'lazy_init');
            $reflection->setAccessible(true);
            $reflection->invoke($kv);
            $socket = $reflection_socket->getValue($kv);
            $this->assertNotNull($socket);
            @socket_shutdown($reflection_socket->getValue($kv));
            @socket_close($reflection_socket->getValue($kv));
            try {
                $kv->redis_send("info");
            } catch (KeyValueException $e) {
                $u = true;
                $this->assertNotNull($e->getCode());
                $this->assertGreaterThan(0, $e->getCode());
            }
            if (!$u) {
                $this->fail('RedisStorage::redis_send does not throw Exception on closed socket');
            }
        }

        /**
         * @backupGlobals
         */
        function testGeneral_real() {
            for ($i = 0; $i < 10; $i++) {// Зачем 100 раз? Потому что Redis, вот зачем
                $this->set_value_sub([]);
            }
        }

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
         * @var array $params
         *
         * @return AbstractStorage|RedisStorage
         */
        protected function get_kv_storage($params) {
            return parent::get_kv_storage($params);
        }
    }

?>