# PSR-16 compatible Key Value storage implementation

## Current status
### General
[![Build Status](https://secure.travis-ci.org/nokitakaze/php-keyvalue.png?branch=master)](http://travis-ci.org/nokitakaze/php-keyvalue)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/nokitakaze/php-keyvalue/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/nokitakaze/php-keyvalue/)
[![Code Coverage](https://scrutinizer-ci.com/g/nokitakaze/php-keyvalue/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/nokitakaze/php-keyvalue/)
<!-- [![Latest stable version](https://img.shields.io/packagist/v/nokitakaze/keyvalue.svg?style=flat-square)](https://packagist.org/packages/nokitakaze/keyvalue) -->

## Usage
At first
```bash
composer require nokitakaze/keyvalue
```

And then
```php
require_once 'vendor/autoload.php';

// 1
$file_storage = new FileStorage([
    'folder' => '/dev/shm',
]);
$file_storage->set('foo', 'bar');
echo $file_storage->get('foo', 'bar');

// 2
$file_storage = new FileStorage([
    'folder' => '/dev/shm',
]);
$file_storage->set('foo', 'bar');
echo $file_storage->get('foo', 123);

// 3
$redis_storage = new RedisStorage([
    'database' => 1,
]);
$redis_storage->set('foo', 'bar');
echo $redis_storage->get('foo', 'default_value');

// @todo Заменить
```
