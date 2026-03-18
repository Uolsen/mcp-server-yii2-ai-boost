---
name: yii2-events
description: "Use when working with Yii2 event system, attaching event handlers, custom events, ActiveRecord events, class-level events, or behavior event bindings."
version: 1.0.0
---

# Yii2 Events (Advanced Template)

## Instance-Level Events
```php
// Attach handler
$model->on(ActiveRecord::EVENT_BEFORE_INSERT, function ($event) {
    $event->sender->created_at = time();
});

// Attach handler with data
$model->on('myEvent', function ($event) {
    // $event->sender is the object that triggered the event
    // $event->data is the data passed during on()
}, $extraData);

// Detach handler
$model->off(ActiveRecord::EVENT_BEFORE_INSERT, $handler);

// Trigger custom event
$model->trigger('myEvent');
```

## Class-Level Events
```php
use yii\base\Event;

// Attach to all instances of a class (global handler)
Event::on(Post::class, ActiveRecord::EVENT_AFTER_INSERT, function ($event) {
    // Runs for all Post inserts across the entire application
    Yii::info("New post created: {$event->sender->title}", 'app\post');
});

// Detach
Event::off(Post::class, ActiveRecord::EVENT_AFTER_INSERT);
```

Useful in `common/config/bootstrap.php` for cross-app event handling.

## Common ActiveRecord Events
```php
ActiveRecord::EVENT_BEFORE_INSERT
ActiveRecord::EVENT_AFTER_INSERT
ActiveRecord::EVENT_BEFORE_UPDATE
ActiveRecord::EVENT_AFTER_UPDATE
ActiveRecord::EVENT_BEFORE_DELETE
ActiveRecord::EVENT_AFTER_DELETE
ActiveRecord::EVENT_AFTER_FIND
ActiveRecord::EVENT_INIT          // After constructor
ActiveRecord::EVENT_AFTER_REFRESH // After refreshing from DB
```

## Custom Event Class
```php
namespace common\events;

use yii\base\Event;

class OrderEvent extends Event
{
    /** @var \common\models\Order */
    public $order;

    /** @var float */
    public $amount;
}
```

## Custom Events in Components
```php
namespace common\components;

use yii\base\Component;
use common\events\OrderEvent;

class OrderService extends Component
{
    const EVENT_ORDER_COMPLETED = 'orderCompleted';
    const EVENT_ORDER_CANCELLED = 'orderCancelled';

    public function completeOrder($order)
    {
        // ... business logic ...

        $event = new OrderEvent();
        $event->order = $order;
        $event->amount = $order->total;
        $this->trigger(self::EVENT_ORDER_COMPLETED, $event);
    }
}

// Attach handler (e.g., in config bootstrap or controller)
$orderService->on(OrderService::EVENT_ORDER_COMPLETED, function (OrderEvent $event) {
    Yii::$app->mailer->compose('order-confirmation', ['order' => $event->order])
        ->setTo($event->order->user->email)
        ->send();
});
```

## Stop Propagation
```php
$model->on('myEvent', function ($event) {
    $event->handled = true; // Stops further handlers from executing
});
```

## Events in Behaviors
```php
namespace common\behaviors;

use yii\base\Behavior;
use yii\db\ActiveRecord;

class AuditBehavior extends Behavior
{
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'logInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'logUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'logDelete',
        ];
    }

    public function logInsert($event)
    {
        Yii::info("Created: " . get_class($this->owner) . " #{$this->owner->id}", 'audit');
    }

    public function logUpdate($event) { /* ... */ }
    public function logDelete($event) { /* ... */ }
}
```
