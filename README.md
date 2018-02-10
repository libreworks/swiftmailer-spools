# swiftmailer-spools
Additional spools for use with the SwiftMailer library.

This includes spools for PDO and MongoDB.

[![Packagist](https://img.shields.io/packagist/v/libreworks/swiftmailer-spools.svg)](https://packagist.org/packages/libreworks/swiftmailer-spools)
[![Build Status](https://travis-ci.org/libreworks/swiftmailer-spools.svg)](https://travis-ci.org/libreworks/swiftmailer-spools)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/libreworks/swiftmailer-spools/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/libreworks/swiftmailer-spools/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/libreworks/swiftmailer-spools/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/libreworks/swiftmailer-spools/?branch=master)

## Installation

You can install this library using Composer:

```console
$ composer require libreworks/swiftmailer-spools
```

* The master branch (version 2.x) of this project depends on SwiftMailer 6.0+, which requires PHP 7.0.
* Version 1.x of this project depends on SwiftMailer 5.4+, which requires PHP 5.3. It also runs just fine on HHVM.

## Compliance

Releases of this library will conform to [Semantic Versioning](http://semver.org).

Our code is intended to comply with [PSR-1](http://www.php-fig.org/psr/psr-1/) and [PSR-2](http://www.php-fig.org/psr/psr-2/). If you find any issues related to standards compliance, please send a pull request!

## Examples

Here's how to instantiate a `Swift_Mailer` object that uses the spools to send.

```php
$mailer = \Swift_Mailer::newInstance(
    \Swift_SpoolTransport::newInstance(
        $spool // your spool goes here
    )
);
// this e-mail will get spooled
$mailer->send(new \Swift_Message($subject, $body, $contentType, $charset));
```

Here's how to instantiate a `Swift_Transport` to send spooled e-mails.

```php
$transport = \Swift_SmtpTransport::newInstance($smtpHost, $smtpPort, $smtpEncrypt)
    ->setUsername($smtpUsername)
    ->setPassword($smtpPassword);

$spool->recover();
$spool->flushQueue($transport);
```

### PDO Spool

```php
$pdo = new \PDO("mysql:dbname=testdb;host=127.0.0.1", 'user', 'pass');
$spool = new \Swift_PdoSpool(
    $pdo,
    "email", // table
    "id", // primary key field
    "message", // serialized email field
    "sentOn", // sent integer timestamp
);
```

### MongoDB Spool

```php
$manager = new \MongoDB\Driver\Manager("mongodb://localhost:27017");
$rp = new \MongoDB\Driver\ReadPreference(\MongoDB\Driver\ReadPreference::RP_PRIMARY_PREFERRED);
$wr = new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY);
$spool = new \Swift_MongoDbSpool(
    $manager,
    "dbname.emails",
    $rp, // optional
    $wc, // optional
);
```

### Mongo Spool (deprecated in 1.x; removed in 2.x)

```php
$client = new \MongoClient();
$db = new \MongoDB("dbname", $client);
$collection = new \MongoCollection($db, "emails");
$spool = new \Swift_MongoSpool($collection);
```
