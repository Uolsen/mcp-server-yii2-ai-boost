# Yii2 Queue (Advanced Template)

Background job processing via `yii2-queue` extension.

## Installation
```bash
composer require yiisoft/yii2-queue
```

## Configuration
Queue is configured in `common/config/main.php` so jobs can be pushed from any app (frontend, backend) and processed by the console worker.

```php
// common/config/main.php
'bootstrap' => ['queue'],  // Required for event handling
'components' => [
    'queue' => [
        'class' => \yii\queue\db\Queue::class,
        'db' => 'db',
        'tableName' => '{{%queue}}',
        'channel' => 'default',
        'as log' => \yii\queue\LogBehavior::class,
    ],
],
```

### Setup Migration (for db backend)
```bash
php yii migrate --migrationPath=@yii/queue/drivers/db/migrations
```

## Job Classes
Job classes typically live in `common/jobs/` since they can be pushed from any app:

```php
namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;

class SendEmailJob extends BaseObject implements JobInterface
{
    public $userId;
    public $templateName;

    public function execute($queue)
    {
        $user = \common\models\User::findOne($this->userId);
        if ($user) {
            \Yii::$app->mailer->compose($this->templateName, ['user' => $user])
                ->setTo($user->email)
                ->setSubject('Notification')
                ->send();
        }
    }
}
```

### Retryable Job
```php
namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\RetryableJobInterface;

class ProcessPaymentJob extends BaseObject implements RetryableJobInterface
{
    public $orderId;

    public function execute($queue)
    {
        $order = \common\models\Order::findOne($this->orderId);
        // Process payment...
    }

    public function getTtr()
    {
        return 60; // Time to reserve (seconds) before job is considered stuck
    }

    public function canRetry($attempt, $error)
    {
        return $attempt < 3; // Retry up to 3 times
    }
}
```

## Pushing Jobs
```php
// Push from any app (frontend, backend, console)
use common\jobs\SendEmailJob;

// Immediate
$id = Yii::$app->queue->push(new SendEmailJob([
    'userId' => $user->id,
    'templateName' => 'welcome',
]));

// Delayed (run after 60 seconds)
Yii::$app->queue->delay(60)->push(new SendEmailJob([
    'userId' => $user->id,
    'templateName' => 'reminder',
]));

// Push with priority (if backend supports it)
Yii::$app->queue->priority(1)->push(new ProcessPaymentJob([
    'orderId' => $order->id,
]));
```

## Running Workers (Console)
```bash
# Daemon mode - listens continuously (for production with supervisor/systemd)
php yii queue/listen

# Process all pending jobs and exit (for cron)
php yii queue/run

# Listen with specific polling interval (seconds)
php yii queue/listen --verbose=1

# Check queue status
php yii queue/info
```

## Job Events
```php
// common/config/main.php or bootstrap
Yii::$app->queue->on(\yii\queue\Queue::EVENT_AFTER_ERROR, function ($event) {
    Yii::error("Job failed: " . $event->error->getMessage(), 'queue');
});
```

## Available Backends
- `yii\queue\db\Queue` - Database (easiest setup, good for moderate load)
- `yii\queue\redis\Queue` - Redis (fast, requires `yiisoft/yii2-redis`)
- `yii\queue\amqp_interop\Queue` - RabbitMQ (enterprise, requires `php-amqplib`)
- `yii\queue\beanstalk\Queue` - Beanstalkd
- `yii\queue\sqs\Queue` - Amazon SQS
- `yii\queue\gearman\Queue` - Gearman
- `yii\queue\sync\Queue` - Synchronous execution (for testing)
