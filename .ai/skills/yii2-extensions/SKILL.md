---
name: yii2-extensions
description: "Use when working with Yii2 official extensions like Debug Toolbar, Gii, Redis, Elasticsearch, MongoDB, AuthClient, or HTTP Client."
version: 1.0.0
---

# Yii2 Official Extensions (Advanced Template)

## Debug Toolbar
```php
// frontend/config/main-local.php and backend/config/main-local.php (dev only)
if (YII_ENV_DEV) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}
```
Provides request/response inspector, DB query log, profiling, log panel.

## Gii Code Generator
```php
// frontend/config/main-local.php and/or backend/config/main-local.php (dev only)
if (YII_ENV_DEV) {
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        'allowedIPs' => ['127.0.0.1', '::1'],
        'generators' => [
            'crud' => [
                'class' => 'yii\gii\generators\crud\Generator',
                'templates' => [
                    'custom' => '@common/gii/crud',
                ],
            ],
        ],
    ];
}
```
Access at: `/gii` - Model Generator, CRUD Generator, Controller Generator, Form Generator, Module Generator, Extension Generator.

## Common Extensions

### yiisoft/yii2-symfonymailer (Mailer)
```bash
composer require yiisoft/yii2-symfonymailer
```
Replaces the deprecated `yii2-swiftmailer`. See mailer guideline for configuration.

### yiisoft/yii2-redis (Redis)
```bash
composer require yiisoft/yii2-redis
```
```php
// common/config/main.php
'components' => [
    'redis' => [
        'class' => 'yii\redis\Connection',
        'hostname' => 'localhost',
        'port' => 6379,
        'database' => 0,
    ],
    // Can also use for cache and session:
    'cache' => ['class' => 'yii\redis\Cache'],
    'session' => ['class' => 'yii\redis\Session'],
],
```

### yiisoft/yii2-queue (Background Jobs)
```bash
composer require yiisoft/yii2-queue
```
See queue guideline for configuration. Supports DB, Redis, RabbitMQ, Beanstalkd, SQS backends.

### yiisoft/yii2-authclient (OAuth)
```bash
composer require yiisoft/yii2-authclient
```
```php
// common/config/main.php
'components' => [
    'authClientCollection' => [
        'class' => 'yii\authclient\Collection',
        'clients' => [
            'google' => [
                'class' => 'yii\authclient\clients\Google',
                'clientId' => 'xxx',
                'clientSecret' => 'xxx',
            ],
            'github' => [
                'class' => 'yii\authclient\clients\GitHub',
                'clientId' => 'xxx',
                'clientSecret' => 'xxx',
            ],
        ],
    ],
],
```

### yiisoft/yii2-httpclient (HTTP Client)
```bash
composer require yiisoft/yii2-httpclient
```
```php
$client = new \yii\httpclient\Client(['baseUrl' => 'https://api.example.com']);
$response = $client->get('users/1')->send();
$data = $response->getData();

// POST with JSON
$response = $client->post('users', ['name' => 'John'])
    ->setFormat(\yii\httpclient\Client::FORMAT_JSON)
    ->send();
```

### yiisoft/yii2-elasticsearch
```bash
composer require yiisoft/yii2-elasticsearch
```
```php
// common/config/main.php
'components' => [
    'elasticsearch' => [
        'class' => 'yii\elasticsearch\Connection',
        'nodes' => [['http_address' => '127.0.0.1:9200']],
    ],
],
```

### yiisoft/yii2-mongodb
```bash
composer require yiisoft/yii2-mongodb
```
```php
// common/config/main.php
'components' => [
    'mongodb' => [
        'class' => 'yii\mongodb\Connection',
        'dsn' => 'mongodb://localhost:27017/mydb',
    ],
],
```

### yiisoft/yii2-faker (Testing)
```bash
composer require --dev yiisoft/yii2-faker
```
Generates fake data for testing using Faker library.

## Extension Configuration Pattern
Extensions are configured as application components. Place shared extensions in `common/config/main.php`, credentials in `common/config/main-local.php`:

```php
// common/config/main.php
'components' => [
    'redis' => [
        'class' => 'yii\redis\Connection',
    ],
],

// common/config/main-local.php (gitignored)
'components' => [
    'redis' => [
        'hostname' => '127.0.0.1',
        'port' => 6379,
        'password' => 'secret',
    ],
],
```
