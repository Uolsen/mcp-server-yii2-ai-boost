---
name: yii2-mutex
description: "Use when implementing mutual exclusion locks, preventing concurrent execution, distributed locking, or protecting critical sections in cron jobs."
version: 1.0.0
---

# Yii2 Mutex (Advanced Template)

Mutual exclusion locks to prevent concurrent execution of critical sections.

## Configuration
Configure in `common/config/main.php` for shared access, or `console/config/main.php` for console-only usage:

```php
// common/config/main.php (or console/config/main.php)
'components' => [
    'mutex' => [
        'class' => 'yii\mutex\MysqlMutex', // or FileMutex, PgsqlMutex, RedisMutex
    ],
],
```

## Available Backends
- `yii\mutex\FileMutex` - File-based (default, works everywhere)
- `yii\mutex\MysqlMutex` - MySQL `GET_LOCK()` (shared across processes/servers)
- `yii\mutex\PgsqlMutex` - PostgreSQL advisory locks
- `yii\mutex\OracleMutex` - Oracle locks
- `yii\redis\Mutex` - Redis `SETNX` (requires `yiisoft/yii2-redis`, works across servers)

## Usage
```php
$mutex = Yii::$app->mutex;

// Acquire lock (returns false immediately if lock unavailable)
if ($mutex->acquire('my-lock')) {
    try {
        // Critical section - only one process runs this at a time
    } finally {
        $mutex->release('my-lock');
    }
}

// With timeout (wait up to 10 seconds for lock)
if ($mutex->acquire('my-lock', 10)) {
    try {
        // ...
    } finally {
        $mutex->release('my-lock');
    }
}
```

## Console Command Example (Prevent Duplicate Cron Runs)
```php
namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CronController extends Controller
{
    /**
     * Processes pending orders. Prevents parallel execution.
     */
    public function actionProcessOrders()
    {
        if (!Yii::$app->mutex->acquire('cron-process-orders', 0)) {
            $this->stdout("Already running, skipping.\n");
            return ExitCode::OK;
        }

        try {
            // Process orders...
            $orders = \common\models\Order::find()
                ->where(['status' => 'pending'])
                ->limit(100)
                ->all();

            foreach ($orders as $order) {
                $order->process();
            }

            $this->stdout("Processed " . count($orders) . " orders.\n");
        } finally {
            Yii::$app->mutex->release('cron-process-orders');
        }

        return ExitCode::OK;
    }
}
```

## Distributed Locking (Multi-Server)
For applications running on multiple servers, use MySQL or Redis mutex instead of `FileMutex`:

```php
// common/config/main.php
'components' => [
    'mutex' => [
        'class' => 'yii\redis\Mutex',
        'redis' => 'redis',   // Use shared redis component
        'expire' => 30,       // Lock auto-expires after 30 seconds (safety net)
    ],
    'redis' => [
        'class' => 'yii\redis\Connection',
        'hostname' => 'localhost',
        'port' => 6379,
    ],
],
```
