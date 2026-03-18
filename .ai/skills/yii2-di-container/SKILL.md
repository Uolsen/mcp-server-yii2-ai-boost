---
name: yii2-di-container
description: "Use when working with dependency injection, DI container, service locator, interface binding, constructor injection, or application component registration."
version: 1.0.0
---

# Yii2 Dependency Injection (Advanced Template)

## DI Container
```php
// Register class with configuration
Yii::$container->set('common\components\PaymentService', [
    'class' => 'common\components\PaymentService',
    'apiKey' => 'xxx',
]);

// Register singleton
Yii::$container->setSingleton('common\components\PaymentService', [
    'class' => 'common\components\PaymentService',
]);

// Bulk registration
Yii::$container->setDefinitions([
    'common\components\PaymentService' => ['class' => 'common\components\StripePayment'],
    'common\components\NotificationService' => ['class' => 'common\components\EmailNotification'],
]);

Yii::$container->setSingletons([
    'common\components\Cache' => ['class' => 'common\components\RedisCache'],
]);

// Get instance
$service = Yii::$container->get('common\components\PaymentService');
```

## Constructor Injection (automatic)
```php
namespace frontend\controllers;

use common\components\PaymentService;
use yii\web\Controller;

class OrderController extends Controller
{
    private $paymentService;

    public function __construct(
        $id,
        $module,
        PaymentService $paymentService,
        $config = []
    ) {
        $this->paymentService = $paymentService;
        parent::__construct($id, $module, $config);
    }
}
```
Yii2 DI resolves `PaymentService` automatically when creating the controller.

## Interface Binding
```php
// common/config/bootstrap.php or common/config/main.php container definitions
Yii::$container->set(
    'common\interfaces\PaymentInterface',
    'common\components\StripePayment'
);

// Now any class depending on PaymentInterface gets StripePayment
namespace common\components;

class OrderService
{
    private $payment;

    public function __construct(PaymentInterface $payment)
    {
        $this->payment = $payment;
    }
}
```

## Service Locator (Yii::$app)
Application components act as a service locator. Configure in config files:

```php
// common/config/main.php - shared across all apps
'components' => [
    'paymentService' => [
        'class' => 'common\components\StripePayment',
        'apiKey' => 'xxx',
    ],
],

// frontend/config/main.php - frontend only
'components' => [
    'cart' => [
        'class' => 'frontend\components\ShoppingCart',
    ],
],

// backend/config/main.php - backend only
'components' => [
    'dashboard' => [
        'class' => 'backend\components\Dashboard',
    ],
],
```

Access:
```php
Yii::$app->paymentService->charge($amount); // Available in all apps
Yii::$app->cart->addItem($product);          // Only in frontend
Yii::$app->get('paymentService');            // Alternative access
Yii::$app->has('cart');                       // Check if registered
```

## DI Container vs Service Locator
- **DI Container** (`Yii::$container`): For class instantiation rules and interface bindings. Classes resolved automatically via constructor injection.
- **Service Locator** (`Yii::$app->componentName`): For application-level services configured in config files. Lazy-loaded on first access.

Use DI Container for: interface binding, constructor injection, cross-cutting concerns.
Use Service Locator for: application services (db, cache, mailer, user), configured via config files.
