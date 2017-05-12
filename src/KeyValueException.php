<?php

    namespace NokitaKaze\KeyValue;

    use Psr\SimpleCache\InvalidArgumentException;

    /**
     *
     * коды:
     * 1: неправильные настройки запуска
     * 2: не удалось подключиться к хранилищу
     * 3: не удалось записать данные
     *
     * @codeCoverageIgnore
     */
    class KeyValueException extends \Exception implements InvalidArgumentException {
    }

?>