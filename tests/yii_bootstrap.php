<?php

declare(strict_types=1);

/**
 * Bootstrap file for tests requiring Yii2 application context.
 *
 * Creates a minimal Yii2 console application with an in-memory SQLite database.
 * Loaded by ToolTestCase::setUp(), not globally — existing unit tests are unaffected.
 */

// Define test environment constants
defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load Yii2 framework
require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Set alias for test fixtures
Yii::setAlias('@app', __DIR__ . '/fixtures/app');

// Create minimal console application
new \yii\console\Application([
    'id' => 'test-app',
    'basePath' => __DIR__ . '/fixtures/app',
    'controllerNamespace' => 'app\\commands',
    'runtimePath' => sys_get_temp_dir() . '/yii2-ai-boost-test/runtime',
    'components' => [
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'sqlite::memory:',
        ],
    ],
]);
