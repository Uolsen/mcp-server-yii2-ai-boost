# Yii2 Application (Advanced Template)

## Advanced Template Structure
Three separate applications sharing common code:
- **Frontend** (`frontend/`) - Public-facing web application
- **Backend** (`backend/`) - Admin panel web application
- **Console** (`console/`) - CLI for cron jobs, migrations, workers

Shared code lives in `common/` (models, components, config, mail templates).

## Entry Points

### Frontend Web (`frontend/web/index.php`)
```php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../../common/config/bootstrap.php';
require __DIR__ . '/../config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../../common/config/main.php',
    require __DIR__ . '/../../common/config/main-local.php',
    require __DIR__ . '/../config/main.php',
    require __DIR__ . '/../config/main-local.php'
);

(new yii\web\Application($config))->run();
```

### Backend Web (`backend/web/index.php`)
```php
// Same pattern, merging common + backend configs
$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../../common/config/main.php',
    require __DIR__ . '/../../common/config/main-local.php',
    require __DIR__ . '/../config/main.php',
    require __DIR__ . '/../config/main-local.php'
);

(new yii\web\Application($config))->run();
```

### Console (`yii` script at project root)
```php
$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/common/config/main.php',
    require __DIR__ . '/common/config/main-local.php',
    require __DIR__ . '/console/config/main.php',
    require __DIR__ . '/console/config/main-local.php'
);

$exitCode = (new yii\console\Application($config))->run();
exit($exitCode);
```

## Configuration Merging Order
Each app loads configs in this order (later overrides earlier):
1. `common/config/main.php` - Shared base (db, cache, mailer, shared components)
2. `common/config/main-local.php` - Shared environment overrides (db credentials)
3. `{app}/config/main.php` - App-specific base (urlManager, app-specific components)
4. `{app}/config/main-local.php` - App-specific environment overrides (debug, gii)

Parameters merge identically: `common/config/params.php` -> `common/config/params-local.php` -> `{app}/config/params.php` -> `{app}/config/params-local.php`

## Environment Initialization
```bash
php init                                      # Interactive prompt
php init --env=Development --overwrite=All    # Non-interactive
```
Copies files from `environments/dev/` or `environments/prod/` to project root. Generates all `-local.php` files, entry scripts, and `.gitignore` entries.

## Application Properties
```php
Yii::$app->id;           // Application ID (e.g., 'app-frontend', 'app-backend')
Yii::$app->name;         // Application name
Yii::$app->basePath;     // Base path of current app (e.g., /path/to/frontend)
Yii::$app->language;     // Current language
Yii::$app->timeZone;     // Timezone
Yii::$app->params;       // Custom parameters (merged from all config levels)
Yii::$app->charset;      // Character set (default: UTF-8)
Yii::$app->sourceLanguage; // Source language for translations
Yii::$app->defaultRoute; // Default controller/action
```

## Lifecycle
```php
// Bootstrap components (loaded on every request)
'bootstrap' => ['log'],

// Events
Application::EVENT_BEFORE_REQUEST   // Before handling request
Application::EVENT_AFTER_REQUEST    // After handling request
Application::EVENT_BEFORE_ACTION    // Before running action
Application::EVENT_AFTER_ACTION     // After running action
```

## Path Aliases
Defined in `common/config/bootstrap.php`:
```php
Yii::setAlias('@common', dirname(__DIR__));
Yii::setAlias('@frontend', dirname(dirname(__DIR__)) . '/frontend');
Yii::setAlias('@backend', dirname(dirname(__DIR__)) . '/backend');
Yii::setAlias('@console', dirname(dirname(__DIR__)) . '/console');
```

Built-in aliases:
```php
// @app       - basePath of current application (frontend, backend, or console)
// @vendor    - vendor/ directory
// @runtime   - runtime/ of current application
// @webroot   - web root of current web application
// @web       - base URL of current web application
// @bower     - vendor/bower-asset
// @npm       - vendor/npm-asset

// Custom aliases
Yii::setAlias('@uploads', '@frontend/web/uploads');
$path = Yii::getAlias('@uploads/file.jpg');
```

## Typical Config Structure

### common/config/main.php
```php
return [
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'components' => [
        'cache' => ['class' => 'yii\caching\FileCache'],
        'db' => ['class' => 'yii\db\Connection'],  // credentials in main-local.php
        'mailer' => ['class' => 'yii\symfonymailer\Mailer'],
        'authManager' => ['class' => 'yii\rbac\DbManager'],
    ],
];
```

### frontend/config/main.php
```php
return [
    'id' => 'app-frontend',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'frontend\controllers',
    'components' => [
        'user' => [
            'identityClass' => 'common\models\User',
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-frontend', 'httpOnly' => true],
        ],
        'session' => [
            'name' => 'advanced-frontend',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
    ],
];
```

### backend/config/main.php
```php
return [
    'id' => 'app-backend',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'backend\controllers',
    'components' => [
        'user' => [
            'identityClass' => 'common\models\User',
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-backend', 'httpOnly' => true],
        ],
        'session' => [
            'name' => 'advanced-backend',
        ],
    ],
];
```
