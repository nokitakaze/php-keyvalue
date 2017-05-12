#!/usr/bin/php
<?php

    abstract class TestRedisServer {
        const DEFAULT_PORT = 6000;
        const DEFAULT_WAITING_TIME = 300;

        /**
         * @var resource $_server_socket
         */
        private static $_server_socket;
        /**
         * @var integer $_real_port
         */
        private static $_real_port;

        private static $_waiting_time;

        /**
         * @var object[]
         */
        private static $_connections = [];

        private static $_last_connection_time;

        static function start($options) {
            self::$_server_socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (self::$_server_socket === false) {
                throw new Exception('Can not create socket');
            }

            self::$_real_port = isset($options['p']) ? (int) $options['p'] : self::DEFAULT_PORT;
            self::$_waiting_time = isset($options['w']) ? (int) $options['w'] : self::DEFAULT_WAITING_TIME;

            if (@socket_bind(self::$_server_socket, '127.0.0.1', self::$_real_port) === false) {
                echo "can not bind socket: ".socket_strerror(socket_last_error(self::$_server_socket))."\n";

                return;
            }

            if (@socket_listen(self::$_server_socket, 5) === false) {
                echo "can not listen socket: ".socket_strerror(socket_last_error(self::$_server_socket))."\n";

                return;
            }
            socket_set_nonblock(self::$_server_socket);
            self::$_last_connection_time = microtime(true);
            while (true) {
                self::transparent_accept();
                foreach (self::$_connections as &$obj) {
                    if ($obj === null) {
                        continue;
                    }
                    self::process_connection($obj);
                }
            }
        }

        private static function transparent_accept() {
            $input_socket = @socket_accept(self::$_server_socket);
            if ($input_socket === false) {
                if (self::$_last_connection_time + self::DEFAULT_WAITING_TIME < microtime(true)) {
                    echo "No connections\n";
                    exit;
                }

                return;
            }
            self::$_last_connection_time = microtime(true);
            if (file_exists(__DIR__.'/redis_server.dat')) {
                $json = json_decode(file_get_contents(__DIR__.'/redis_server.dat'));
            } else {
                $json = null;
            }

            self::$_connections[] = (object) [
                'socket' => $input_socket,
                'buf' => '',
                'mode' => $json,
            ];
        }

        /**
         * @param object $connection
         */
        private static function process_connection(&$connection) {
            $read = [$connection->socket];
            $count = socket_select($read, $write = null, $except = null, 0);
            if ($count === false) {
                socket_shutdown($connection->socket, 2);
                socket_close($connection->socket);
                $connection = null;

                return;
            } elseif ($count > 0) {
                $bytes = @socket_recv($connection->socket, $buf, 2048, 0);
                if ($bytes == 0) {
                    socket_shutdown($connection->socket, 2);
                    socket_close($connection->socket);
                    $connection = null;

                    return;
                }

                self::process_buf($connection, $buf);
            }

            $except = array($connection->socket);
            $count = socket_select($read = null, $write = null, $except, 0);
            if ($count === false) {
                socket_shutdown($connection->socket, 2);
                socket_close($connection->socket);
                $connection = null;

                return;
            } elseif ($count > 0) {
                socket_shutdown($connection->socket, 2);
                socket_close($connection->socket);
                $connection = null;

                return;
            }
        }

        /**
         * @param object $connection
         * @param string $buf
         */
        private static function process_buf(&$connection, &$buf) {
            $connection->buf .= $buf;
            if (preg_match('_^(.*)\\n_', $connection->buf, $a)) {
                $command = rtrim($a[1], "\\r");
                $connection->buf = substr($connection->buf, strlen($a[1]) + 1);
                self::process_command($connection, $command);
            }
        }

        /**
         * @param object $connection
         */
        private function send_ok(&$connection) {
            if (isset($connection->transaction) and $connection->transaction) {
                socket_write($connection->socket, "+QUEUED\r\n");
            } else {
                socket_write($connection->socket, "+OK\r\n");
            }
        }

        /**
         * @param object $connection
         * @param string $command
         */
        private static function process_command(&$connection, $command) {
            if (!preg_match('_^(\\S+)_', strtolower($command), $a)) {
                socket_shutdown($connection->socket, 2);
                socket_close($connection->socket);
                $connection = null;

                return;
            } elseif ($connection->mode->mode == 'loading') {
                socket_write($connection->socket, "-LOADING Redis is loading the dataset in memory\r\n");

                return;
            }
            if (isset($connection->mode->timeout)) {
                sleep($connection->mode->timeout);

                return;
            }

            switch ($a[1]) {
                case 'quit':
                    socket_shutdown($connection->socket, 2);
                    socket_close($connection->socket);
                    $connection = null;

                    return;
                case 'hgetall':
                    if ($connection->mode->mode == 'malformed_hgetall') {
                        socket_write($connection->socket, "nyanpasu\r\n");
                    } elseif ($connection->mode->mode == 'hgetall_malformed_123') {
                        socket_write($connection->socket, "*2\r\n$\r\nnyan\r\n$4\r\npasu\r\n");// Тут специально ошибка
                    } elseif ($connection->mode->mode == 'hgetall_long_answer') {
                        socket_write($connection->socket, "*2\r\n$4\r\nny");
                        sleep(1);
                        socket_write($connection->socket, "an\r\n$4\r\npasu\r\n");
                    } elseif ($connection->mode->mode == 'hgetall_long_answer2') {
                        socket_write($connection->socket, "*2\r\n$4\r\nnyan\r\n$4");
                        sleep(1);
                        socket_write($connection->socket, "\r\npasu\r\n");
                    } else {
                        socket_write($connection->socket, "*2\r\n$4\r\nnyan\r\n$4\r\npasu\r\n");
                    }
                    break;
                case 'get':
                    if ($connection->mode->mode == 'empty_value') {
                        socket_write($connection->socket, "$\r\n");
                    } elseif ($connection->mode->mode == 'long_answer') {
                        socket_write($connection->socket, "$10\r\n12345");
                        sleep(1);
                        socket_write($connection->socket, "67890\r\n");
                    } elseif ($connection->mode->mode == 'get_non64') {
                        socket_write($connection->socket, "$1\r\n?\r\n");
                    } elseif ($connection->mode->mode == 'get_nongzip') {
                        socket_write($connection->socket, "$1\r\n=\r\n");
                    } else {
                        $s = base64_encode(gzdeflate(serialize('nyanpasu')));
                        socket_write($connection->socket, sprintf("$%d\r\n%s\r\n", strlen($s), $s));
                    }
                    break;
                case 'ttl':
                    if ($connection->mode->mode == 'ttl_malformed') {
                        socket_write($connection->socket, ":a\r\n");
                    } elseif ($connection->mode->mode == 'ttl_minus') {
                        socket_write($connection->socket, ":-10\r\n");
                    } else {
                        socket_write($connection->socket, ":10\r\n");
                    }
                    break;
                case 'multi':
                    if ($connection->mode->mode == 'multi_malformed') {
                        socket_write($connection->socket, "-ERR DeadDead\r\n");
                    } else {
                        self::send_ok($connection);
                        $connection->transaction = true;
                    }
                    break;
                case 'expireat':
                    if (strpos($command, ':value') === false) {
                        if ($connection->mode->mode == 'expireat_malformed') {
                            socket_write($connection->socket, "-ERR DeadDead\r\n");
                        } else {
                            self::send_ok($connection);
                        }
                    } else {
                        if ($connection->mode->mode == 'expireat_malformed2') {
                            socket_write($connection->socket, "-ERR DeadDead\r\n");
                        } else {
                            self::send_ok($connection);
                        }
                    }
                    break;
                case 'exec':
                    if ($connection->mode->mode == 'exec_malformed') {
                        socket_write($connection->socket, "-ERR DeadDead\r\n");
                    } elseif ($connection->mode->mode == 'exec_commit_set_expire') {
                        socket_write($connection->socket, "*2\r\n:1\r\n:1\r\n");
                    } elseif ($connection->mode->mode == 'exec_commit_set_expire_pause') {
                        socket_write($connection->socket, "*2\r\n:1");
                        sleep(1);
                        socket_write($connection->socket, "\r\n:1\r\n");
                    } elseif ($connection->mode->mode == 'exec_commit_set_expire') {
                        socket_write($connection->socket, "*2\r\n:1\r\n:1\r\n");
                    } else {
                        socket_write($connection->socket, "-ERR WTF?\r\n");
                    }
                    $connection->transaction = false;
                    break;
                case 'info':
                    if ($connection->mode->mode == 'info_big_chunk') {
                        $buf = '';
                        for ($i = 0; $i < 10000; $i++) {
                            $buf .= chr(mt_rand(ord('a'), ord('z')));
                        }
                        socket_write($connection->socket, "Server:\r\n{$buf}\r\n");
                    } elseif ($connection->mode->mode == 'info_wait_before_crlf') {
                        $buf = '';
                        for ($i = 0; $i < 10000; $i++) {
                            $buf .= chr(mt_rand(ord('a'), ord('z')));
                        }
                        socket_write($connection->socket, "Server:{$buf}");
                        sleep(1);
                        socket_write($connection->socket, "\r\n");
                    } elseif ($connection->mode->mode == 'info_wait_before_crlf2') {
                        for ($i = 0; $i < 10; $i++) {
                            $buf = '';
                            for ($i = 0; $i < 10; $i++) {
                                $buf .= chr(mt_rand(ord('a'), ord('z')));
                            }
                            socket_write($connection->socket, $buf);
                            sleep(1);
                        }
                        socket_write($connection->socket, "\r\n");
                    } elseif ($connection->mode->mode == 'info_wait_before_crlf_timeout10') {
                        $buf = '';
                        for ($i = 0; $i < 100; $i++) {
                            $buf .= chr(mt_rand(ord('a'), ord('z')));
                        }
                        socket_write($connection->socket, $buf);
                        sleep(10);
                        socket_write($connection->socket, "\r\n");
                    } else {
                        socket_write($connection->socket, "Server:\r\nnyan pasu\r\n");
                    }
                    break;
                case 'del':
                    if ($connection->mode->mode == 'del_error') {
                        socket_write($connection->socket, "-ERR Can not delete value\r\n");
                    } else {
                        socket_write($connection->socket, ":1\r\n");
                    }
                    break;
                case 'hmset':
                    if ($connection->mode->mode == 'hmset_error') {
                        socket_write($connection->socket, "-ERR Can not hmset\r\n");
                    } else {
                        self::send_ok($connection);
                    }
                    break;
                case 'append':
                case 'set':
                    if (strpos($command, ':value') === false) {
                        if ($connection->mode->mode == 'set_error') {
                            socket_write($connection->socket, "-ERR Can not hmset\r\n");
                        } else {
                            self::send_ok($connection);
                        }
                    } else {
                        if ($connection->mode->mode == 'set_error2') {
                            socket_write($connection->socket, "-ERR Can not hmset\r\n");
                        } else {
                            self::send_ok($connection);
                        }
                    }

                    break;
                case 'discard':
                    if (isset($connection->transaction) and $connection->transaction) {
                        socket_write($connection->socket, "+OK\r\n");// @todo поправить на правильный
                        $connection->transaction = false;
                    } else {
                        socket_write($connection->socket, "-ERR transaction has not been started\r\n");
                    }
                    break;

                case 'shutdown':
                    exit;

                default:
                    socket_write($connection->socket, "-ERR WTF?\r\n");

                    return;
            }
        }

        static function stop() {
            @socket_shutdown(self::$_server_socket);
            @socket_close(self::$_server_socket);
        }
    }

    pcntl_signal(SIGTERM, function ($sig_no) {
        switch ($sig_no) {
            case SIGTERM:
                TestRedisServer::stop();
                exit;
            case SIGHUP:
                // handle restart tasks
                break;
            case SIGUSR1:
                echo "Caught SIGUSR1...\n";
                break;
            default:
                // handle all other signals
        }
    });

    $options = getopt('p:w:', []);
    echo "Start Test redis server\n";
    TestRedisServer::start($options);
?>