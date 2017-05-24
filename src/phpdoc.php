<?php

    namespace NokitaKaze\KeyValue;

    /**
     *
     * General
     * @property integer $storage_type
     * @property integer $region_type
     * @property string  $prefix
     * @property boolean $multi_folder_mutex
     *
     * File
     * @property string  $folder
     * @property boolean $multi_folder
     *
     * Redis
     * @property integer $database
     * @property string  $host
     * @property integer $port
     * @property double  $timeout
     * @property integer $string_chunk_size
     */
    interface KeyValueSettings {
    }

    /**
     * Interface KeyValueDatum
     * @package NokitaKaze\KeyValue
     *
     * @property string|integer $key
     * @property double         $time_create
     * @property double         $time_expires
     * @property string|null    $host
     * @property mixed          $value
     * @property string|null    $init_file
     * @property integer|null   $init_line
     * @property integer|null   $pid
     */
    interface KeyValueDatum {
    }

?>