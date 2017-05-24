<?php

    namespace NokitaKaze\KeyValue;

    use NokitaKaze\Mutex\FileMutex;
    use NokitaKaze\Mutex\MutexInterface;

    class StorageTest extends \PHPUnit_Framework_TestCase {
        function testGet_value() {
            $kv = new KV1;
            $kv->value = (object) ['value' => 'data', 'time_expires' => time() + 3600];
            $this->assertEquals('data', $kv->get_value(''));
            $this->assertEquals('data', $kv->get_value('', 'nyan'));
            //
            $kv->value = null;
            $this->assertEquals('', $kv->get_value(''));
            $this->assertNotNull($kv->get_value(''));
            $this->assertEquals('nyan', $kv->get_value('', 'nyan'));
            $this->assertNull($kv->get_value('', null));
            //
            $kv->value = (object) ['value' => 'data', 'time_expires' => 0];
            $this->assertEquals('', $kv->get_value(''));
            $this->assertNotNull($kv->get_value(''));
            $this->assertEquals('nyan', $kv->get_value('', 'nyan'));
            $this->assertNull($kv->get_value('', null));
            //
            $kv->value = (object) ['value' => 'data', 'time_expires' => -1];
            $this->assertEquals('', $kv->get_value(''));
            $this->assertNotNull($kv->get_value(''));
            $this->assertEquals('nyan', $kv->get_value('', 'nyan'));
            $this->assertNull($kv->get_value('', null));
        }

        function testGet_value_full() {
            $kv = new KV1;
            $kv->value = (object) ['value' => 'data', 'time_expires' => time() + 3600];
            $this->assertNotNull($kv->get_value_full(''));
            $kv->value = null;
            $this->assertNull($kv->get_value_full(''));
            $kv->value = (object) ['value' => 'data', 'time_expires' => 0];
            $this->assertNull($kv->get_value_full(''));
            $kv->value = (object) ['value' => 'data', 'time_expires' => -1];
            $this->assertNull($kv->get_value_full(''));
            $kv->value = (object) ['value' => 'data', 'time_expires' => time() - 1];
            $this->assertNull($kv->get_value_full(''));
            $kv->value = (object) ['value' => 'data', 'time_expires' => time()];
            $this->assertNull($kv->get_value_full(''));
        }

        /**
         * @backupGlobals
         */
        function testGet_environment() {
            AbstractStorage::get_environment('host');
            AbstractStorage::get_environment('root');
            $u = false;
            try {
                AbstractStorage::get_environment('nyanpasu');
            } catch (KeyValueException $e) {
                $u = true;
            }
            if (!$u) {
                $this->fail('AbstractStorage::get_environment did not throw Exception');
            }

            //
            $_SERVER['HTTP_HOST'] = 'example.com';
            $this->assertEquals('example.com', AbstractStorage::get_environment('host'));
            $_SERVER['HTTP_HOST'] = 'nyanpasu.com';
            $this->assertEquals('nyanpasu.com', AbstractStorage::get_environment('host'));
            //
            $_SERVER['DOCUMENT_ROOT'] = '/tmp';
            $this->assertEquals('/tmp', AbstractStorage::get_environment('root'));
            $_SERVER['DOCUMENT_ROOT'] = '/dev/shm';
            $this->assertEquals('/dev/shm', AbstractStorage::get_environment('root'));
        }

        function testForm_datum_value() {
            $data_set = [
                ['nyan', 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertEquals('nyan', $value->value);
                }],
                [null, 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertNull($value->value);
                }],
                [true, 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertNotNull($value->value);
                    $obj::assertTrue($value->value);
                }],
                [false, 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertNotNull($value->value);
                    $obj::assertFalse($value->value);
                }],
                [0, 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertNotNull($value->value);
                    $obj::assertEquals(0, $value->value);
                }],
                ['', 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertNotNull($value->value);
                    $obj::assertEquals('', $value->value);
                }],
                [[], 100, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertNotNull($value->value);
                    $obj::assertInternalType('array', $value->value);
                }],
                [(object) [], 0, function ($value, \PHPUnit_Framework_TestCase $obj) {
                    /** @var KeyValueDatum $value */
                    $obj::assertEquals(posix_getpid(), $value->pid);
                    $obj::assertLessThan(microtime(true), $value->time_expires);
                }],
            ];

            foreach ([false, true] as &$u1) {
                if ($u1) {
                    $kv = new KV1;
                    $reflectionMethod = new \ReflectionMethod($kv, 'form_datum_value');
                    $reflectionMethod->setAccessible(true);
                }
                foreach ($data_set as &$datum) {
                    if (!$u1) {
                        $kv = new KV1;
                        $reflectionMethod = new \ReflectionMethod($kv, 'form_datum_value');
                        $reflectionMethod->setAccessible(true);
                    }
                    /**
                     * @var \ReflectionMethod $reflectionMethod
                     * @var KV1               $kv
                     */
                    $value = $reflectionMethod->invoke($kv, 'foobar', $datum[0], $datum[1]);
                    $this->assertInternalType('object', $value);
                    foreach (['time_create', 'time_expires', 'init_file', 'init_line', 'host', 'value', 'key', 'pid'] as $key) {
                        if (!array_key_exists($key, $value)) {
                            $this->fail(sprintf('Key `%s` does not exist in value. With value=%s; exp=%d',
                                $key, $datum[0], $datum[1]));
                        }
                    }
                    $this->assertNotNull($value->time_create);
                    $this->assertGreaterThanOrEqual(microtime(true) - 3, $value->time_create);
                    $this->assertLessThan(microtime(true), $value->time_create);
                    $this->assertNotNull($value->time_expires);
                    $this->assertGreaterThanOrEqual(microtime(true) - 3 + $datum[1], $value->time_expires);
                    $this->assertLessThan(microtime(true) + 100, $value->time_expires);
                    $this->assertNotNull($value->init_file);
                    call_user_func($datum[2], $value, $this);
                }
            }
        }

        function dataStandard_prefix_strategy_suite2() {
            $hosts = ['example.com', 'www.example.com', 'nyan.pasu'];
            $roots = ['/tmp', '/dev/shm'];
            $data = [];
            foreach ($hosts as &$host1) {
                foreach ($roots as &$root1) {
                    foreach ([KV1::RegionDomain, KV1::RegionServer, KV1::RegionFolder] as &$type) {
                        $data[] = [$host1, $root1, $type, $hosts, $roots];
                    }
                }
            }

            return $data;
        }

        /**
         */
        function testStandard_prefix_strategy_suite1() {
            $kv = new KV1;
            $kv->standard_prefix_strategy();
            $this->assertArrayHasKey('region_type', (array) $kv->get_settings());
            $this->assertEquals(KV1::RegionDomain, $kv->get_settings()->region_type);
            //
            $kv = new KV1;
            $kv->set_settings((object) []);
            $kv->standard_prefix_strategy();
            $this->assertArrayHasKey('region_type', (array) $kv->get_settings());
            $this->assertEquals(KV1::RegionDomain, $kv->get_settings()->region_type);
            //
            $kv = new KV1;
            $kv->set_settings((object) ['prefix' => 'nyan']);
            $kv->standard_prefix_strategy();
            $this->assertArrayHasKey('region_type', (array) $kv->get_settings());
            $this->assertEquals(KV1::RegionDomain, $kv->get_settings()->region_type);
            $this->assertEquals('nyan', $kv->get_settings()->prefix);
        }

        /**
         * @param string   $host1
         * @param string   $root1
         * @param string   $type
         * @param string[] $hosts
         * @param string[] $roots
         *
         * @backupGlobals
         * @dataProvider dataStandard_prefix_strategy_suite2
         */
        function testStandard_prefix_strategy_suite2($host1, $root1, $type, $hosts, $roots) {
            $_SERVER['HTTP_HOST'] = $host1;
            $_SERVER['DOCUMENT_ROOT'] = $root1;
            $kv = new KV1;
            $kv->set_settings((object) ['region_type' => $type]);
            $kv->standard_prefix_strategy();
            $value1 = $kv->get_prefix();
            foreach ($hosts as $host2) {
                foreach ($roots as $root2) {
                    $_SERVER['HTTP_HOST'] = $host2;
                    $_SERVER['DOCUMENT_ROOT'] = $root2;
                    $kv = new KV1;
                    $kv->set_settings((object) ['region_type' => $type]);
                    $kv->standard_prefix_strategy();
                    $value1a = $kv->get_prefix();
                    switch ($type) {
                        case KV1::RegionDomain:
                            if (self::sad_safe_domain($host1) == self::sad_safe_domain($host2)) {
                                $this->assertEquals($value1, $value1a);
                            } else {
                                $this->assertNotEquals($value1, $value1a);
                            }
                            break;
                        case KV1::RegionServer:
                            $this->assertEquals($value1, $value1a);
                            break;
                        case KV1::RegionFolder:
                            if ($root1 == $root2) {
                                $this->assertEquals($value1, $value1a);
                            } else {
                                $this->assertNotEquals($value1, $value1a);
                            }
                            break;
                    }
                }
            }
        }

        /**
         * @backupGlobals
         */
        function testStandard_prefix_strategy_suite3() {
            $_SERVER['HTTP_HOST'] = 'example.com';
            $kv = new KV1;
            $kv->set_settings((object) ['region_type' => KV1::RegionDomain]);
            $kv->standard_prefix_strategy();
            $value1 = $kv->get_prefix();

            foreach ([0, 1, 9, 10, 80, 443, 8080, 1080, 4443, 8002, 10000, 65535] as $port) {
                $_SERVER['HTTP_HOST'] = 'example.com:'.$port;
                $kv = new KV1;
                $kv->set_settings((object) ['region_type' => KV1::RegionDomain]);
                $kv->standard_prefix_strategy();
                $value1a = $kv->get_prefix();
                $this->assertEquals($value1, $value1a);
                //
                $_SERVER['HTTP_HOST'] = 'www.example.com:'.$port;
                $kv = new KV1;
                $kv->set_settings((object) ['region_type' => KV1::RegionDomain]);
                $kv->standard_prefix_strategy();
                $value1a = $kv->get_prefix();
                $this->assertEquals($value1, $value1a);
            }
        }

        function testStandard_prefix_strategy_suite4() {
            //
            $kv = new KV1;
            $kv->set_settings((object) ['region_type' => mt_rand(5, 100)]);
            $u = false;
            try {
                $kv->standard_prefix_strategy();
            } catch (KeyValueException $e) {
                $u = true;
            }
            if (!$u) {
                $this->fail('AbstractStorage::standard_prefix_strategy did not throw Exception');
            }
        }

        function testGet_expires_time() {
            $this_time = microtime(true);
            for ($i = 0; $i < 100; $i++) {
                $kv = new KV1;
                $t = $this_time + mt_rand(5, 100);
                $kv->value = (object) ['time_expires' => $t];
                $this->assertEquals($t, $kv->get_expires_time(''));
            }
            $kv = new KV1;
            $this->assertNull($kv->get_expires_time(''));

            $kv->set_value('nyan', 'pasu', 0);
            /** @noinspection PhpUndefinedFieldInspection */
            unset($kv->value->time_expires);
            $this->assertNull($kv->get_expires_time(''));
        }

        function testSet_expires_time() {
            for ($i = 0; $i < 100; $i++) {
                $kv = new KV1;
                $kv->set_value('', mt_rand(0, 100), mt_rand(1000, 10 * 3600));
                $t = mt_rand(5, 100);
                $kv->set_expires_time('', $t);
                $this->assertArrayHasKey('time_expires', (array) $kv->value);
                /** @noinspection PhpUndefinedFieldInspection */
                $this->assertGreaterThan(microtime(true), $kv->value->time_expires);
                /** @noinspection PhpUndefinedFieldInspection */
                $this->assertLessThanOrEqual(microtime(true) + $t, $kv->value->time_expires);
            }

            $kv = new KV1;
            $kv->set_value('', mt_rand(0, 100), mt_rand(1000, 10 * 3600));
            $kv->value = null;
            $kv->set_expires_time('', mt_rand(1000, 10 * 3600));
        }

        /**
         * @covers \NokitaKaze\KeyValue\AbstractStorage::add_profiling
         * @covers \NokitaKaze\KeyValue\AbstractStorage::get_profiling
         */
        function testAdd_profiling() {
            $reflection = new \ReflectionProperty('\\NokitaKaze\\KeyValue\\AbstractStorage', '_profiling');
            $reflection->setAccessible(true);
            $reflection->setValue(null, []);
            $data = AbstractStorage::get_profiling();
            $this->assert_get_profiling($data);
            $this->assertEquals(0, count($data['class']));
            $this->assertEquals(0, count($data['action']));
            $this->assertEquals(0, $data['all']);

            //
            $method = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\AbstractStorage', 'add_profiling');
            $method->setAccessible(true);
            $method->invoke(null, 2, 'foo', 'bar');
            $data = AbstractStorage::get_profiling();
            $this->assert_get_profiling($data);
            $this->assertEquals(1, count($data['class']));
            $this->assertEquals(1, count($data['action']));
            $this->assertEquals(2, $data['all']);
            $this->assertEquals(2, $data['class']['foo']);
            $this->assertEquals(2, $data['action']['bar']);
            //
            $method->invoke(null, 3.5, 'nyan', 'pasu');
            $data = AbstractStorage::get_profiling();
            $this->assert_get_profiling($data);
            $this->assertEquals(2, count($data['class']));
            $this->assertEquals(2, count($data['action']));
            $this->assertEquals(5.5, $data['all']);
            $this->assertEquals(2, $data['class']['foo']);
            $this->assertEquals(2, $data['action']['bar']);
            $this->assertEquals(3.5, $data['class']['nyan']);
            $this->assertEquals(3.5, $data['action']['pasu']);
            //
            $method->invoke(null, 5.2, 'foo', 'pasu');
            $data = AbstractStorage::get_profiling();
            $this->assert_get_profiling($data);
            $this->assertEquals(2, count($data['class']));
            $this->assertEquals(2, count($data['action']));
            $this->assertEquals(10.7, $data['all']);
            $this->assertEquals(7.2, $data['class']['foo']);
            $this->assertEquals(2, $data['action']['bar']);
            $this->assertEquals(3.5, $data['class']['nyan']);
            $this->assertEquals(8.7, $data['action']['pasu']);
        }

        private function assert_get_profiling($data) {
            $this->assertInternalType('array', $data);
            $this->assertArrayHasKey('class', $data);
            $this->assertInternalType('array', $data['class']);
            $this->assertArrayHasKey('action', $data);
            $this->assertInternalType('array', $data['action']);
            $this->assertArrayHasKey('all', $data);
            $this->assertContains(gettype($data['all']), ['double', 'integer']);
            foreach ($data['class'] as $key => $value) {
                $this->assertContains(gettype($data['class'][$key]), ['double', 'integer']);
            }
            foreach ($data['action'] as $key => $value) {
                $this->assertContains(gettype($data['action'][$key]), ['double', 'integer']);
            }
        }

        /**
         * @param string $domain
         *
         * @return string
         */
        static function sad_safe_domain($domain) {
            $a = explode(':', strtolower($domain), 2);
            $s = $a[0];
            if (preg_match_all('|\\.|', $s, $sad_tmp) > 1) {
                $s = preg_replace('|^www\\.(.+?)$|', '$1', $s);
            }

            return $s;
        }

        function testBaseGet_lock() {
            $reflection_lock = new \ReflectionProperty('\\NokitaKaze\\KeyValue\\AbstractStorage', '_locks');
            $reflection_lock->setAccessible(true);

            $reflection_method = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\AbstractStorage', 'get_lock');
            $reflection_method->setAccessible(true);
            $reflection_release_method = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\AbstractStorage', 'release_lock');
            $reflection_release_method->setAccessible(true);
            /**
             * @var AbstractStorage $instance_plain
             */
            $instance_plain = new KV1((object) []);
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
                     */
                    // @todo Сделать чере NokitaKaze\\Mutex\\MutexInterface
                    $this->assertInstanceOf('\\NokitaKaze\\Mutex\\FileMutex', $lock);
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
    }

    class KV1 extends AbstractStorage {
        var $value = null;
        var $deleted = false;

        function __construct($obj = null) {
            $this->settings = (object) [];
        }

        function set_value($key, $value, $ttl = 315576000) {
            $this->value = $this->form_datum_value($key, $value, $ttl);
        }

        function delete_value($key) {
            $this->deleted = true;
        }

        function get_value_full_clear($key) {
            return $this->value;
        }

        /**
         * Паблик Морозов для settings
         * @return object
         */
        function get_settings() {
            return $this->settings;
        }

        /**
         * Паблик Морозов для settings
         *
         * @param  object $settings
         */
        function set_settings($settings) {
            $this->settings = $settings;
        }
    }

?>