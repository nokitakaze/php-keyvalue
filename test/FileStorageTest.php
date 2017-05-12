<?php

    namespace NokitaKaze\KeyValue;

    require_once __DIR__.'/KVAbstractTest.php';

    class FileStorageTest extends KVAbstractTest {
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

        function test__construct() {
            $kv = new FileStorage((object) ['storage_type' => AbstractStorage::StorageTemporary,]);
            $folder1 = $kv->folder;
            $kv = new FileStorage((object) ['storage_type' => AbstractStorage::StoragePersistent,]);
            $this->assertNotEquals($folder1, $kv->folder);

            //
            $u = false;
            try {
                new FileStorage((object) ['storage_type' => mt_rand(3, 10)]);
            } catch (\Exception $e) {
                $u = true;
            }
            if (!$u) {
                $this->fail('FileStorage did not throw exception on malformed settings');
            }
        }

        /**
         * @backupGlobals
         */
        function testSet_value() {
            $this->set_value_sub(['multi_folder' => false]);
            $this->set_value_sub(['multi_folder' => false]);// @hint Это не ошибка, этот тест прогоняется дважды
            $this->set_value_sub(['multi_folder' => true]);
        }

        /**
         * @param array $params
         *
         * @return object
         */
        protected function get_full_params($params) {
            return (object) array_merge(['folder' => $this->_folder], $params);
        }

        /**
         * @param array $params
         */
        function set_value_sub($params) {
            parent::set_value_sub($params);
            /**
             * @var FileStorage $kv
             */
            $kv = $this->_last_kv;
            $reflection_lock = new \ReflectionProperty($kv, '_locks');
            $reflection_lock->setAccessible(true);

            //
            $filename = $kv->get_filename('key');
            touch($filename.'.tmp');
            chmod($filename.'.tmp', 0);
            $u = false;
            $exception_code1 = null;
            try {
                $kv->set_value('key', 'value');
            } catch (KeyValueException $e) {
                $u = true;
                /** @noinspection PhpUnusedLocalVariableInspection */
                $exception_code1 = $e->getCode();
                $this->assertLockNotAcquired($reflection_lock->getValue($kv), 'key');
            }
            if (!$u) {
                $this->fail('FileStorage::set_value did not throw Exception on readonly temporary db filename');
            }
            @unlink($filename.'.tmp');
            // @todo Покрыть тестами неполучившийся rename
            /*
            $kv = new KV2((object) ['folder' => $this->_folder]);
            $u = false;
            touch($kv->get_filename('key').'.tmp');
            chmod(dirname($kv->get_filename('key')), 0);
            try {
                $kv->set_value('key', 'value');
            } catch (KeyValueException $e) {
                $u = true;
                $this->assertNotEquals($exception_code1, $e->getCode());
                if (($reflection_lock->getValue($kv)!==null) and $reflection_lock->getValue($kv)->lock_acquired){
                    $this->fail('Lock has not been released on KeyValueException on FileStorage::set_value');
                }
            }
            if (!$u) {
                $this->fail('FileStorage::set_value did not throw Exception on readonly db filename');
            }
            chmod(dirname($kv->get_filename('key')), 7 << 6);
            @unlink($kv->get_filename('key').'.tmp');
            */
        }

        function dataGet_filename() {
            $files = (object) ['list' => [],];
            $mutex_keys = (object) ['list' => [],];
            $data = [];
            foreach ([$this->_folder, '/dev/shm'] as $folder) {
                foreach (['', 'nya_'] as $prefix) {
                    foreach (['historia', 'christa'] as $key) {
                        foreach ([false, true] as $multi) {
                            $data[] = [$folder, $prefix, $key, $multi, $files, $mutex_keys];
                        }
                    }
                }
            }

            return $data;
        }

        /**
         * @param        $folder
         * @param        $prefix
         * @param        $key
         * @param        $multi
         * @param object $files
         * @param object $mutex_keys
         *
         * @covers       \NokitaKaze\KeyValue\FileStorage::get_filename
         * @covers       \NokitaKaze\KeyValue\FileStorage::get_mutex_key_name
         * @covers       \NokitaKaze\KeyValue\FileStorage::delete_value
         *
         * @dataProvider dataGet_filename
         */
        function testGet_filename($folder, $prefix, $key, $multi, $files, $mutex_keys) {
            $kv = new FileStorage((object) [
                'folder' => $folder,
                'prefix' => $prefix,
                'multi_folder' => $multi,
            ]);
            $filename = $kv->get_filename($key);
            $this->assertNotContains($filename, $files->list);
            $files->list[] = $filename;

            //
            $mutex = $kv->get_mutex_key_name($key);
            $this->assertNotContains($mutex, $mutex_keys->list);
            $mutex_keys->list[] = $mutex;

            //
            $kv1 = new FileStorage((object) [
                'folder' => $folder,
                'prefix' => $prefix,
                'multi_folder' => $multi,
            ]);
            $this->assertEquals($filename, $kv1->get_filename($key));
            $this->assertEquals($mutex, $kv1->get_mutex_key_name($key));
        }

        function testGet_value_full_clear() {
            $kv = new FileStorage((object) ['folder' => $this->_folder]);
            $key = mt_rand(ord('a'), ord('z')).mt_rand(ord('a'), ord('z')).mt_rand(ord('a'), ord('z'));
            $filename = $kv->get_filename($key);

            $reflection = new \ReflectionMethod($kv, 'get_value_full_clear');
            $reflection->setAccessible(true);
            $lock = new \ReflectionProperty($kv, '_locks');
            $lock->setAccessible(true);
            $this->assertNull($reflection->invoke($kv, $key));
            $this->assertLockNotAcquired($lock->getValue($kv), $key);
            $this->assertLockNotAcquiredByOthers($kv, $key);
            file_put_contents($filename, serialize('nyanpasu'), LOCK_EX);
            //
            chmod($filename, 0);
            $this->assertNull($reflection->invoke($kv, $key));
            $this->assertLockNotAcquired($lock->getValue($kv), $key);
            $this->assertLockNotAcquiredByOthers($kv, $key);
            //
            chmod($filename, 6 << 6);
            $this->assertLockNotAcquired($lock->getValue($kv), $key);
            $this->assertLockNotAcquiredByOthers($kv, $key);
            $this->assertEquals('nyanpasu', $reflection->invoke($kv, $key));
            //
            file_put_contents($filename, serialize(false), LOCK_EX);
            $this->assertLockNotAcquired($lock->getValue($kv), $key);
            $this->assertLockNotAcquiredByOthers($kv, $key);
            $this->assertFalse($reflection->invoke($kv, $key));
            //
            file_put_contents($filename, 'nyanpasu', LOCK_EX);
            $this->assertNull($reflection->invoke($kv, $key));
            $this->assertLockNotAcquired($lock->getValue($kv), $key);
            $this->assertLockNotAcquiredByOthers($kv, $key);

            unlink($filename);
        }

        function testMultiFolder() {
            $storage = new FileStorage((object) ['multi_folder' => true, 'folder' => $this->_folder]);
            $storage1 = new FileStorage((object) ['folder' => $this->_folder]);
            $this->assertNull($storage->get_value('key', null));
            $this->assertNull($storage->get('key', null));
            foreach (['key', 'foobar', 'nyanpasu'] as $key) {
                $s1 = $storage->get_filename($key);
                $s2 = $storage1->get_filename($key);
                $this->assertInternalType('string', $s1);
                $this->assertInternalType('string', $s2);
                $this->assertEquals(strlen($s2) + 6, strlen($s1));
                $this->assertRegExp('_/[0-9a-f]{2,2}/[0-9a-f]{2,2}/_', substr($s1, strlen($this->_folder)));

                $value = self::generate_hash();
                $storage->set_value($key, $value);
                $this->assertEquals($value, $storage->get_value($key, null));
                $this->assertEquals($value, $storage->get($key, null));
            }
        }

        function testCreate_path() {
            $reflection = new \ReflectionMethod('\\NokitaKaze\\KeyValue\\FileStorage', 'create_path');
            $reflection->setAccessible(true);

            $root_folder = self::get_tmp_dir().'/nkt_test_'.self::generate_hash();
            $key = self::generate_hash();
            $hash = hash('sha512', $key);

            $u = false;
            try {
                $reflection->invoke(null, $root_folder.'/nyanpasu', true, $key);
            } catch (KeyValueException $e) {
                $u = true;
            }
            if (!$u) {
                $this->fail('FileStorage::create_path did not throw Exception on non existed root folder');
            }
            if (!mkdir($root_folder)) {
                throw new \Exception('Can not create root folder '.$root_folder);
            }

            system(sprintf('rm -rf %s', escapeshellarg($root_folder)));
            foreach ([false, true] as &$u1) {
                mkdir($root_folder);
                $folder = $root_folder.'/nyanpasu';
                if ($u1) {
                    mkdir($folder);
                }
                $reflection->invoke(null, $folder, true, $key);
                $this->assertFileExists($folder);

                $folder .= '/'.substr($hash, 0, 2);
                $this->assertFileExists($folder);
                $folder .= '/'.substr($hash, 2, 2);
                $this->assertFileExists($folder);
                system(sprintf('rm -rf %s', escapeshellarg($root_folder)));
            }
        }

        function dataMultiValue() {
            return [
                [['multi_folder' => false], 100],
                [['multi_folder' => true], 100],
                [['multi_folder' => false], 10000],
                [['multi_folder' => true], 10000],
            ];
        }

        function testNoExceptionOnSet() {
            /**
             * @var FileStorage $kv
             */
            $kv = $this->get_kv_storage(['folder' => __DIR__.'/non_existed_folder/'.mt_rand(0, 1000000)]);
            $this->assertFalse($kv->set('12343', 10));

            $kv->clear();
            $this->assertTrue(true);
        }

        protected function clear_test(/** @noinspection PhpSignatureMismatchDuringInheritanceInspection */
            FileStorage $kv, $count) {
            $filename = "{$kv->folder}/ascetkey_".$kv->get_prefix().'.dat';
            file_put_contents($filename, '1');
            $filenames = [$filename];

            for ($i = 0; $i < 100; $i++) {
                $k1 = mt_rand(0, 255);
                $k2 = mt_rand(0, 255);
                if ($k1 < 16) {
                    $k1 = '0'.dechex($k1);
                } else {
                    $k1 = dechex($k1);
                }
                if ($k2 < 16) {
                    $k2 = '0'.dechex($k2);
                } else {
                    $k2 = dechex($k2);
                }
                if (!file_exists($kv->folder.'/'.$k1)) {
                    mkdir($kv->folder.'/'.$k1);
                    mkdir($kv->folder.'/'.$k1.'/'.$k2);
                } elseif (!file_exists($kv->folder.'/'.$k1.'/'.$k2)) {
                    mkdir($kv->folder.'/'.$k1.'/'.$k2);
                }

                // 1
                $filename = "{$kv->folder}/{$k1}/{$k2}/ascetkey_".$kv->get_prefix().'.dat';
                file_put_contents($filename, '1');
                $filenames[] = $filename;

                // 2
                $filename = "{$kv->folder}/ascetkey_".$kv->get_prefix().'.dat';
                file_put_contents($filename, '1');
                $filenames[] = $filename;

                // 3
                $filename = "{$kv->folder}/{$k1}/{$k2}/ascetkey_".hash('sha256', mt_rand(100000, 500000)).
                            hash('sha512', mt_rand(100000, 500000)).'.dat';
                file_put_contents($filename, '1');
                $filenames[] = $filename;

                // 4
                $filename = "{$kv->folder}/ascetkey_".hash('sha256', mt_rand(100000, 500000)).
                            hash('sha512', mt_rand(100000, 500000)).'.dat';
                file_put_contents($filename, '1');
                $filenames[] = $filename;
            }
            $filenames = array_unique($filenames);

            parent::clear_test($kv, $count);
            foreach ($filenames as &$filename) {
                $this->assertFileExists($filename);
            }
            foreach ($filenames as &$filename) {
                unlink($filename);
            }
        }
    }

?>