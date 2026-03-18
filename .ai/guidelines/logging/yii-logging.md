# Yii2 Logging (Advanced Template)

## Log Methods
```php
Yii::error('Message', 'category');    // Errors - always logged
Yii::warning('Message', 'category');  // Warnings
Yii::info('Message', 'category');     // Informational
Yii::trace('Message', 'category');    // Debug (only when YII_DEBUG is true)
```

## Configuration (Advanced Template)
Log component is typically configured in `common/config/main.php` for shared targets, with app-specific log files using per-app `@runtime`:

```php
// common/config/main.php
'components' => [
    'log' => [
        'traceLevel' => YII_DEBUG ? 3 : 0,
        'targets' => [
            // Errors/warnings to file (each app writes to its own @runtime/logs/)
            [
                'class' => 'yii\log\FileTarget',
                'levels' => ['error', 'warning'],
                'logFile' => '@runtime/logs/app.log',
            ],
        ],
    ],
],
```

### Per-Application Log Targets
```php
// frontend/config/main.php - add frontend-specific targets
'components' => [
    'log' => [
        'targets' => [
            [
                'class' => 'yii\log\FileTarget',
                'levels' => ['error'],
                'categories' => ['frontend\*'],
                'logFile' => '@runtime/logs/frontend-errors.log',
            ],
        ],
    ],
],

// backend/config/main.php
'components' => [
    'log' => [
        'targets' => [
            [
                'class' => 'yii\log\FileTarget',
                'levels' => ['error'],
                'categories' => ['backend\*'],
                'logFile' => '@runtime/logs/backend-errors.log',
            ],
        ],
    ],
],
```

### All Available Targets
```php
// File target (most common)
[
    'class' => 'yii\log\FileTarget',
    'levels' => ['error', 'warning'],
    'categories' => ['app\*'],
    'logFile' => '@runtime/logs/app.log',
    'maxFileSize' => 1024,     // KB, rotate at this size
    'maxLogFiles' => 5,        // Keep 5 rotated files
],

// Database target
[
    'class' => 'yii\log\DbTarget',
    'levels' => ['error'],
    // Requires migration: php yii migrate --migrationPath=@yii/log/migrations
],

// Email target (for critical errors)
[
    'class' => 'yii\log\EmailTarget',
    'levels' => ['error'],
    'categories' => ['yii\db\*'],
    'message' => [
        'from' => 'noreply@example.com',
        'to' => ['admin@example.com'],
        'subject' => 'Application Error',
    ],
],

// Syslog target
[
    'class' => 'yii\log\SyslogTarget',
    'levels' => ['error', 'warning'],
    'identity' => 'my-app',
],
```

## Categories
```php
// Log with category (use namespace-style for filtering)
Yii::info('User logged in', 'common\auth');
Yii::error('Payment failed', 'frontend\payment');
Yii::warning('Slow query detected', 'backend\reports');

// Filter by category in target
'categories' => ['common\*'],              // All common categories
'categories' => ['frontend\*', 'common\*'], // Frontend + common
'except' => ['yii\debug\*'],               // Exclude debug toolbar logs
```

## Profiling
```php
Yii::beginProfile('heavyOperation', 'app\performance');
// ... code to profile ...
Yii::endProfile('heavyOperation', 'app\performance');
```

## Log File Locations (Advanced Template)
```
frontend/runtime/logs/app.log    # Frontend logs
backend/runtime/logs/app.log     # Backend logs
console/runtime/logs/app.log     # Console logs
```
Each application writes to its own `@runtime/logs/` directory since `@runtime` resolves to the current app's runtime folder.
