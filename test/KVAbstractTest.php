<?php

    namespace NokitaKaze\KeyValue;

    use NokitaKaze\Mutex\MutexInterface;
    use NokitaKaze\Mutex\FileMutex;

    abstract class KVAbstractTest extends \PHPUnit_Framework_TestCase {
        private $_class_name;
        private static $_overloads_exist = [];
        private $_class_filename;
        private $_overloads_exist_in_this_instance = false;

        static function get_tmp_dir() {
            return sys_get_temp_dir().'/nkt_test';
        }

        function setUp() {
            parent::setUp();
            if (!file_exists(self::get_tmp_dir())) {
                mkdir(self::get_tmp_dir());
            }
            $this->require_overload();
        }

        protected static function get_class() {
            return substr(static::class, 0, -4);
        }

        protected static function get_class_overloaded() {
            return static::get_class().'TestOverload';
        }

        private function require_overload() {
            $this->_class_name = static::get_class();
            $a = explode('\\', static::get_class_overloaded());
            $overloaded_name = $a[count($a) - 1];
            unset($a);
            if (in_array($overloaded_name, self::$_overloads_exist)) {
                return;
            }
            // @todo Делать это через мок объекты
            $this->_class_filename = tempnam(self::get_tmp_dir(), 'nkt_test_');
            chmod($this->_class_filename, 6 << 6);
            $res = file_put_contents($this->_class_filename, sprintf('<'.'?php

    namespace NokitaKaze\KeyValue;

    class %s extends \\%s implements KVTestOverload {
        var $_lock_ack = [];

        function release_lock_ack() {
            $this->_lock_ack = [];
        }

        /**
         * @return boolean
         */
        function get_lock_ack($key) {
            return in_array($key, $this->_lock_ack);
        }

        function get_lock($key) {
            $this->_lock_ack[$key] = true;
            parent::get_lock($key);
        }
    }
?>', $overloaded_name, $this->_class_name));
            if ($res === false) {
                throw new \Exception('Can not create test overload class file');
            }
            /** @noinspection PhpIncludeInspection */
            require_once($this->_class_filename);
            self::$_overloads_exist[] = $overloaded_name;
            $this->_overloads_exist_in_this_instance = true;
        }

        function tearDown() {
            if ($this->_overloads_exist_in_this_instance) {
                unlink($this->_class_filename);
            }
            parent::tearDown();
        }

        protected static function generate_hash($key_length = 20) {
            $hashes = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
            $hash = '';
            for ($i = ord('a'); $i <= ord('z'); $i++) {
                array_push($hashes, chr($i), strtoupper(chr($i)));
            }
            for ($i = 0; $i < $key_length; $i++) {
                $hash .= $hashes[mt_rand(0, count($hashes) - 1)];
            }

            return $hash;
        }

        /**
         * @param array $params
         *
         * @return object
         */
        protected function get_full_params($params) {
            return (object) $params;
        }

        /**
         * @var array $params
         *
         * @return AbstractStorage
         */
        protected function get_kv_storage($params) {
            $class_name = $this->_class_name;

            return new $class_name($this->get_full_params($params));
        }

        /**
         * @var AbstractStorage|null
         */
        protected $_last_kv = null;

        /**
         * Общий тест, проверяющий то, что значения не теряются
         *
         * @param array $params
         *
         * @throws \Exception
         * @backupGlobals
         */
        function set_value_sub($params) {
            $_SERVER['HTTP_HOST'] = 'example.com';
            $_SERVER['DOCUMENT_ROOT'] = '/dev/shm';
            $this->set_value_sub_low_level($params);
            unset($_SERVER['HTTP_HOST'], $_SERVER['DOCUMENT_ROOT']);
            $this->set_value_sub_low_level($params);
        }

        protected function set_value_sub_low_level($params) {
            /**
             * @var AbstractStorage $kv
             * @var AbstractStorage $kv1
             */
            $kv = $this->get_kv_storage($params);
            $this->assertEquals($this->_class_name, get_class($kv));
            $this->_last_kv = $kv;
            $kv1 = $this->get_kv_storage($params);
            $this->assertEquals($this->_class_name, get_class($kv1));
            $kv->delete_value('key');
            $this->assertNull($kv->get_value('key', null));
            $this->assertNull($kv->get('key', null));
            $this->assertNull($kv->get_change_time('key'));
            $this->assertNull($kv1->get_value('key', null));
            $this->assertNull($kv1->get('key', null));
            $this->assertNull($kv1->get_change_time('key'));
            $t = $kv->get_value('key', '');
            $this->assertNotNull($t);
            $this->assertNotNull($kv->get('key', ''));
            $this->assertEquals('', $t);
            $t = $kv->get_value('key', 'nyan');
            $this->assertNotNull($t);
            $this->assertNotNull($kv->get('key', 'nyan'));
            $this->assertEquals('nyan', $t);

            //
            $kv->set_value('key', 'value', 3600);
            $this->assertTrue($kv->has('key'));
            $t = $kv->get_value('key', null);
            $this->assertNotNull($t);
            $this->assertNotNull($kv->get_change_time('key'));
            $this->assertEquals('value', $t);
            //
            if (!self::time_expires_accurate()) {
                $kv->set_value('key', 'value', 0);
                $this->assertTrue($kv->has('key'));
                $t = $kv->get_value('key', null);
                $this->assertNull($t);
                $this->assertNull($kv->get_change_time('key'));
                $t = $kv->get_value('key', 'nyan');
                $this->assertEquals('nyan', $t);
            }
            //
            foreach (['pasu', '', "\\", "'", '"', 1, 1.0, 2.234, true, null, [], ['nyan' => 'pasu'], (object) []] as $value) {
                $kv->set_value('key', $value, 3600);
                $this->assertTrue($kv->has('key'));
                $t = $kv->get_value('key', null);
                if (is_null($value)) {
                    $this->assertNull($t);
                    $this->assertNull($kv->get('key', null));
                    continue;
                }

                $this->assertNotNull($t);
                $this->assertInternalType(gettype($value), $t);
                if (is_array($t)) {
                    $this->assertEquals(count($value), count($t));
                } elseif (!is_bool($t) and !is_object($t)) {
                    $this->assertEquals($value, $t);
                }

                $full_t = $kv->get_value_full('key');
                $this->assertLessThan(2, abs($full_t->time_create - $kv->get_change_time('key')));

                $kv1->set_value('key', $value, 3600);
                $t1 = $kv1->get_value('key', null);
                if (!is_array($t) and !is_object($t)) {
                    $this->assertEquals($t, $t1);
                    $this->assertEquals($t, $kv1->get('key', null));
                }
                $kv1->delete('key');
                $this->assertNull($kv1->get_value('key', null));
                $this->assertNull($kv1->get('key', null));
            }

            // @todo Большой текст

            $kv->delete_value('key');
            $kv1->delete_value('key');
            $this->assertFalse($kv->has('key'));
            $this->assertFalse($kv1->has('key'));
        }

        /**
         * Проверяем, что с одной стороны мьютекс дёргается но с другой — освобождается, а не зависает
         * @large
         */
        function testGet_lockBehavior() {
            $key = self::generate_hash(20);

            $reflection_lock = new \ReflectionProperty($this->_class_name, '_locks');
            $reflection_lock->setAccessible(true);

            /**
             * Проверяем, что с одной стороны мьютекс дёргается но с другой — освобождается, а не зависает
             * @var AbstractStorage|KVTestOverload $instance
             */
            $class_name = static::get_class_overloaded();
            $instance = new $class_name($this->get_full_params([]));
            $this->assertLockNotAcquired($reflection_lock->getValue($instance), $key);
            $this->assertLockNotAcquiredByOthers($instance, $key);
            // Сначала удаляем, чтобы точно не было
            $instance->delete_value($key);
            $this->assertFalse($instance->has($key));
            $this->assertLockNotAcquired($reflection_lock->getValue($instance), $key);
            $this->assertLockNotAcquiredByOthers($instance, $key);
            // Пишем
            $instance->set_value($key, null, 0);
            $this->assertTrue($instance->get_lock_ack($key));
            $instance->release_lock_ack();
            $this->assertLockNotAcquired($reflection_lock->getValue($instance), $key);
            $this->assertLockNotAcquiredByOthers($instance, $key);
            // Читаем
            $instance->get_value($key, null);
            $this->assertTrue($instance->get_lock_ack($key));
            $instance->release_lock_ack();
            $this->assertLockNotAcquired($reflection_lock->getValue($instance), $key);
            $this->assertLockNotAcquiredByOthers($instance, $key);
            // Удаляем
            $instance->delete_value($key);
            $this->assertTrue($instance->get_lock_ack($key));
            $this->assertFalse($instance->has($key));
            $instance->release_lock_ack();
            $this->assertLockNotAcquired($reflection_lock->getValue($instance), $key);
            $this->assertLockNotAcquiredByOthers($instance, $key);
        }

        function testGet_lock() {
            if (method_exists($this->_class_name, 'get_lock')) {
                // Нет овверайдинга этой функции
            }
            $reflection_lock = new \ReflectionProperty($this->_class_name, '_locks');
            $reflection_lock->setAccessible(true);
            $reflection_method = new \ReflectionMethod($this->_class_name, 'get_lock');
            $reflection_method->setAccessible(true);
            $reflection_release_method = new \ReflectionMethod($this->_class_name, 'release_lock');
            $reflection_release_method->setAccessible(true);
            $class_name = $this->_class_name;
            /**
             * @var AbstractStorage $instance_plain
             */
            $instance_plain = new $class_name($this->get_full_params([]));
            $old_filenames_for_locks = [];
            foreach (['key', 'nyan', 'pasu', 'foo', 'bar'] as $num => &$key) {
                $instance_plain->get_value($key, null);
                $reflection_method->invoke($instance_plain, $key);
                $reflection_release_method->invoke($instance_plain);
                /**
                 * @var MutexInterface[]|null[] $locks
                 */
                $locks = $reflection_lock->getValue($instance_plain);
                $this->assertInternalType('array', $locks);
                $this->assertEquals($num + 1, count($locks));
                foreach ($locks as $lock_name => &$lock) {
                    if (is_null($lock)) {
                        continue;
                    }
                    /**
                     * @var FileMutex $lock
                     * @todo Убрать этот блок кода
                     * @todo поменять на MutexInterface
                     */
                    $this->assertEquals('NokitaKaze\\Mutex\\FileMutex', get_class($lock));
                    if (isset($old_filenames_for_locks[$lock_name])) {
                        $this->assertEquals($old_filenames_for_locks[$lock_name], $lock->filename);
                    } else {
                        $old_filenames_for_locks[$lock_name] = $lock->filename;
                    }
                }
            }
            unset($old_filenames_for_locks);

            $locks = $reflection_lock->getValue($instance_plain);
            $used_files = [];
            foreach ($locks as &$lock) {
                if (is_null($lock)) {
                    continue;
                }
                $this->assertNotContains($lock->filename, $used_files);
                $used_files[] = $lock->filename;
            }
        }

        /**
         * @param MutexInterface[]|null[] $locks
         * @param string                  $key
         *
         * Проверяем что эта блокировка не взята В ЭТОМ инстансе
         */
        protected function assertLockNotAcquired($locks, $key) {
            if (isset($locks[$key]) and $locks[$key]->is_acquired()) {
                $this->fail(sprintf('Lock `%s` has not been released on %s', $key, $this->_class_name));
            }
        }

        /**
         * @param AbstractStorage $instance
         * @param string          $key
         *
         * Проверяем что эта блокировка не взята В ЛЮБОМ ДРУГОМ инстансе
         */
        protected function assertLockNotAcquiredByOthers($instance, $key) {
            $created = false;
            $reflection = new \ReflectionProperty($instance, '_locks');
            $reflection->setAccessible(true);
            if (!isset($reflection->getValue($instance)[$key])) {
                $created = true;
                $ref_method = new \ReflectionMethod($instance, 'create_lock');
                $ref_method->setAccessible(true);
                $ref_method->invoke($instance, $key);
            }

            $this->assertArrayHasKey($key, $reflection->getValue($instance));
            /**
             * @var MutexInterface $lock
             */
            $lock = $reflection->getValue($instance)[$key];
            $this->assertTrue($lock->is_free(), sprintf('Lock `%s` has not been released on %s', $key, $this->_class_name));
            if ($created) {
                $locks = $reflection->getValue($instance);
                unset($locks[$key]);
                $reflection->setValue($instance, $locks);
            }
        }

        function dataGet_expires_time() {
            return [[false], [true]];
        }

        /**
         * @param boolean $u
         *
         * @dataProvider dataGet_expires_time
         */
        function testGet_expires_time_suite1($u) {
            $key = self::generate_hash(20);
            /**
             * @var AbstractStorage $kv
             */
            $class_name = $this->_class_name;
            $kv = new $class_name($this->get_full_params([]));
            $kv->delete_value($key);
            $this->assertFalse($kv->has($key));
            if ($u) {
                $kv = new $class_name($this->get_full_params([]));
            }
            for ($i = 0; $i < 100; $i++) {
                if (!$u) {
                    $kv = new $class_name($this->get_full_params([]));
                }
                $ttl = mt_rand(10, 10000);
                $this_time = microtime(true);
                $kv->set_value($key, 'value', $ttl);
                $this->assertTrue($kv->has($key));
                $after_action_time = microtime(true);
                $expires_time = $kv->get_expires_time($key);
                if (!static::time_expires_accurate()) {
                    $this->assertLessThanOrEqual(3, abs($this_time + $ttl - $expires_time));
                    $this->assertLessThanOrEqual(3, abs($after_action_time + $ttl - $expires_time));
                } else {
                    $this->assertGreaterThanOrEqual($this_time + $ttl, $expires_time);
                    $this->assertLessThanOrEqual($after_action_time + $ttl, $expires_time);
                }
                $change_time = $kv->get_change_time($key);
                if (!static::time_expires_accurate()) {
                    $this->assertLessThanOrEqual(3, abs($this_time - $change_time));
                    $this->assertLessThanOrEqual(3, abs($after_action_time - $change_time));
                } else {
                    $this->assertGreaterThanOrEqual($this_time, $change_time);
                    $this->assertLessThanOrEqual($after_action_time, $change_time);
                }

                $raw = $kv->get_value_full($key);
                if (!static::time_expires_accurate()) {
                    $this->assertLessThanOrEqual(3, abs($expires_time - $raw->time_expires));
                } else {
                    $this->assertEquals($expires_time, $raw->time_expires);
                }
                if (!static::time_create_accurate()) {
                    $this->assertLessThanOrEqual(3, abs($change_time - $raw->time_create));
                } else {
                    $this->assertEquals($change_time, $raw->time_create);
                }
                $this->assertEquals($change_time, $raw->time_create);

                // Set Expires time
                $ttl = mt_rand(10, 10000);
                $this_time = microtime(true);
                $kv->set_expires_time($key, $ttl);
                $after_action_time = microtime(true);
                $expires_time = $kv->get_expires_time($key);
                if (!static::time_expires_accurate()) {
                    $this->assertLessThanOrEqual(3, abs($this_time + $ttl - $expires_time));
                } else {
                    $this->assertGreaterThanOrEqual($this_time + $ttl, $expires_time);
                }
                if (!static::time_expires_accurate()) {
                    $this->assertLessThanOrEqual(3, abs($after_action_time + $ttl - $expires_time));
                } else {
                    $this->assertLessThanOrEqual($after_action_time + $ttl, $expires_time);
                }
            }
        }

        function testDelete_value_suite2() {
            $key = self::generate_hash(20);
            /**
             * @var AbstractStorage $kv
             */
            $class_name = $this->_class_name;
            $kv = new $class_name($this->get_full_params([]));
            $kv->set_value($key, 'value', 3600);
            $this->assertTrue($kv->has($key));
            $kv->delete_value($key);
            $this->assertFalse($kv->has($key));
            $this->assertNull($kv->get_value($key, null));
            $this->assertNull($kv->get($key, null));
            $kv->delete_value($key);// @hint Нет, это не ошибка, я специально делаю это дважды
            $this->assertNull($kv->get_value($key, null));
            $this->assertNull($kv->get($key, null));
            $this->assertFalse($kv->has($key));
        }

        /**
         * Время просрочки верное
         *
         * @return bool
         */
        static function time_expires_accurate() {
            return true;
        }

        /**
         * Время создания/обновление записи верное
         *
         * @return bool
         */
        static function time_create_accurate() {
            return true;
        }

        function dataMultiValue() {
            return [[[], 0]];
        }

        protected function clear_test(AbstractStorage $kv, $count) {
            // Тестируем clear
            $kv->clear();
            for ($i = 0; $i < $count; $i++) {
                $this->assertFalse($kv->has($i));
            }
        }

        /**
         * @param array   $params
         * @param integer $count
         *
         * @dataProvider dataMultiValue
         * @large
         */
        function testMultiValue(array $params, $count) {
            $kv = $this->get_kv_storage($params);
            $locks = new \ReflectionProperty($kv, '_locks');
            $locks->setAccessible(true);

            foreach ([false, true] as $u) {
                for ($i = 0; $i < $count; $i++) {
                    $kv->set_value($i, mt_rand(1, 1000), 600);
                    $this->assertTrue($kv->has($i));
                }
                $this->assertEquals($count, count($locks->getValue($kv)));
                $kv->clear_mutex_list(20000);
                $this->assertEquals($count, count($locks->getValue($kv)));
                $kv->clear_mutex_list($u ? min(10, $count) : null);
                $this->assertEquals(0, count($locks->getValue($kv)));

                $this->clear_test($kv, $count);
            }
        }

        function dataExceptionOnMalformedKey() {
            return [
                [12.3],
                [null],
                ['{foo}'],
                ['(bar)'],
            ];
        }

        /**
         * @param mixed $key
         *
         * @dataProvider dataExceptionOnMalformedKey
         * @expectedException \NokitaKaze\KeyValue\KeyValueException
         */
        function testExceptionOnMalformedKey_get($key) {
            $kv = $this->get_kv_storage([]);
            $kv->get($key);
        }

        function dataMultiple() {
            $data = [];
            for ($i = 0; $i < 3; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $data[] = [$i, $j];
                }
            }

            return $data;
        }

        /**
         * @param mixed $key
         *
         * @dataProvider dataExceptionOnMalformedKey
         * @expectedException \NokitaKaze\KeyValue\KeyValueException
         */
        function testExceptionOnMalformedKey_set($key) {
            $kv = $this->get_kv_storage([]);
            $kv->set($key, 'foobar');
        }

        /**
         * @param mixed $key
         *
         * @dataProvider dataExceptionOnMalformedKey
         * @expectedException \NokitaKaze\KeyValue\KeyValueException
         */
        function testExceptionOnMalformedKey_has($key) {
            $kv = $this->get_kv_storage([]);
            $kv->has($key);
        }

        /**
         * @param mixed $key
         *
         * @dataProvider dataExceptionOnMalformedKey
         * @expectedException \NokitaKaze\KeyValue\KeyValueException
         */
        function testExceptionOnMalformedKey_delete($key) {
            $kv = $this->get_kv_storage([]);
            $kv->delete($key);
        }

        /**
         * @param integer $type1
         * @param integer $type2
         *
         * @covers \NokitaKaze\KeyValue\AbstractStorage::getMultiple
         * @covers \NokitaKaze\KeyValue\AbstractStorage::setMultiple
         * @covers \NokitaKaze\KeyValue\AbstractStorage::get
         * @covers \NokitaKaze\KeyValue\AbstractStorage::set
         * @covers \NokitaKaze\KeyValue\AbstractStorage::deleteMultiple
         *
         * @throws \Exception
         * @dataProvider dataMultiple
         */
        function testMultiple($type1, $type2) {
            $kv = $this->get_kv_storage([]);
            $list = [
                'key_'.mt_rand(10000000, 99999999) => mt_rand(10000000, 99999999),
                'key_'.mt_rand(10000000, 99999999) => mt_rand(10000000, 99999999),
                'key_'.mt_rand(10000000, 99999999) => mt_rand(10000000, 99999999),
            ];
            if ($type1 == 0) {
                foreach ($list as $key => &$value) {
                    $kv->set_value($key, $value);
                }
            } elseif ($type1 == 1) {
                foreach ($list as $key => &$value) {
                    $kv->set($key, $value);
                }
            } elseif ($type1 == 2) {
                $kv->setMultiple($list);
            } else {
                throw new \Exception('Code contract bridge');
            }

            $keys = array_keys($list);
            if ($type2 == 0) {
                foreach ($list as $key => &$value) {
                    $this->assertEquals($value, $kv->get_value($key));
                }
            } elseif ($type2 == 1) {
                foreach ($list as $key => &$value) {
                    $this->assertEquals($value, $kv->get($key));
                }
            } elseif ($type2 == 2) {
                $this->assertEquals($list, $kv->getMultiple($keys));
            } else {
                throw new \Exception('Code contract bridge');
            }

            $kv->deleteMultiple($keys);
            foreach ($keys as &$key) {
                $this->assertFalse($kv->has($key));
            }
        }
    }

    interface KVTestOverload {
        function release_lock_ack();

        /**
         * @param string $key
         *
         * @return boolean
         */
        function get_lock($key);

        /**
         * @param string $key
         *
         * @return boolean
         */
        function get_lock_ack($key);
    }

?>