# Yii2 2.0.45 Framework Guidelines (Advanced Template)

## Application Structure

### Advanced Template (`yii2-app-advanced`)
Multi-tier application with separated frontend, backend, console, and shared common code.

```
project-root/
├── common/               # Shared across all applications
│   ├── config/            # main.php, main-local.php, params.php, params-local.php, bootstrap.php
│   ├── models/            # Shared AR models (User, LoginForm)
│   ├── components/        # Shared components
│   ├── mail/              # Shared email templates
│   ├── tests/             # Common tests
│   └── widgets/           # Shared widgets
├── frontend/              # Public-facing web application
│   ├── assets/            # Asset bundles
│   ├── config/            # main.php, main-local.php, params.php, params-local.php
│   ├── controllers/       # Frontend controllers
│   ├── models/            # Frontend-specific models
│   ├── views/             # Frontend views and layouts
│   ├── web/               # Public root (index.php, assets/)
│   ├── widgets/           # Frontend widgets
│   └── runtime/           # Generated files, logs, cache
├── backend/               # Admin panel web application
│   ├── assets/            # Asset bundles
│   ├── config/            # main.php, main-local.php, params.php, params-local.php
│   ├── controllers/       # Backend controllers
│   ├── models/            # Backend-specific models
│   ├── views/             # Backend views and layouts
│   ├── web/               # Public root (index.php, assets/)
│   ├── widgets/           # Backend widgets
│   └── runtime/           # Generated files, logs, cache
├── console/               # Console application (cron, migrations)
│   ├── config/            # main.php, main-local.php, params.php, params-local.php
│   ├── controllers/       # Console commands
│   ├── migrations/        # Database migrations
│   ├── models/            # Console-specific models
│   └── runtime/           # Generated files, logs
├── environments/          # Environment configs (dev, prod)
│   ├── dev/               # Development overrides
│   └── prod/              # Production overrides
├── vendor/                # Composer dependencies
├── init                   # Environment initialization script
├── init.bat               # Windows init script
├── yii                    # Console entry point
├── composer.json
└── requirements.php
```

### Namespaces
- Common: `common\models\*`, `common\components\*`
- Frontend: `frontend\controllers\*`, `frontend\models\*`
- Backend: `backend\controllers\*`, `backend\models\*`
- Console: `console\controllers\*`, `console\models\*`

### Path Aliases (defined in `common/config/bootstrap.php`)
```php
Yii::setAlias('@common', dirname(__DIR__));
Yii::setAlias('@frontend', dirname(dirname(__DIR__)) . '/frontend');
Yii::setAlias('@backend', dirname(dirname(__DIR__)) . '/backend');
Yii::setAlias('@console', dirname(dirname(__DIR__)) . '/console');
```

### Configuration Merging Order
Each application merges configs in this order (later overrides earlier):
```
common/config/main.php          → shared base config
common/config/main-local.php    → shared environment-specific (gitignored)
{app}/config/main.php           → app-specific base config
{app}/config/main-local.php     → app-specific environment-specific (gitignored)
```
Parameters follow the same pattern with `params.php` and `params-local.php`.

### Environment Initialization
```bash
php init    # Interactive: choose Development or Production
php init --env=Development --overwrite=All    # Non-interactive
```
The `init` script copies files from `environments/{env}/` to the project root, generating all `-local.php` config files and entry scripts.

---

## Controllers

```php
namespace frontend\controllers;

use yii\web\Controller;

class SiteController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['actions' => ['login'], 'allow' => true],
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['delete' => ['POST']],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index');
    }
}
```

### Request/Response
```php
$request = Yii::$app->request;
$id = $request->get('id');
$data = $request->post('User');

throw new NotFoundHttpException('Not found');
throw new ForbiddenHttpException('Access denied');
```

---

## Models

### Shared Active Record (common/models/)
```php
namespace common\models;

use yii\db\ActiveRecord;

class User extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%user}}';
    }

    public function rules()
    {
        return [
            [['username', 'email'], 'required'],
            ['email', 'email'],
            ['username', 'string', 'max' => 255],
            ['username', 'unique'],
            ['status', 'in', 'range' => [1, 2, 3]],
        ];
    }

    public function getProfile()
    {
        return $this->hasOne(Profile::class, ['user_id' => 'id']);
    }

    public function getPosts()
    {
        return $this->hasMany(Post::class, ['user_id' => 'id']);
    }
}
```

### Model Placement
- **`common/models/`** - Shared across apps (User, LoginForm, base AR models)
- **`frontend/models/`** - Frontend-specific (SignupForm, ContactForm, search models)
- **`backend/models/`** - Backend-specific (admin forms, report models)

### Common Validators
```php
['field', 'required']
['field', 'string', 'max' => 255]
['field', 'integer']
['email', 'email']
['field', 'unique']
['field', 'in', 'range' => [1, 2, 3]]
['date', 'date', 'format' => 'php:Y-m-d']
['field', 'safe']
```

### Scenarios
```php
public function scenarios()
{
    return [
        'create' => ['username', 'email', 'password'],
        'update' => ['username', 'email'],
    ];
}

$model->scenario = 'create';
```

### Form Models
```php
namespace frontend\models;

use yii\base\Model;

class ContactForm extends Model
{
    public $name;
    public $email;
    public $body;

    public function rules()
    {
        return [
            [['name', 'email', 'body'], 'required'],
            ['email', 'email'],
        ];
    }
}
```

---

## Views

### Rendering
```php
// Controller
return $this->render('view', ['model' => $model]);
return $this->renderPartial('_partial', ['model' => $model]);

// View - always encode user input
<?= Html::encode($userInput) ?>
<?= HtmlPurifier::process($richText) ?>
```

### Layouts (per-application)
```php
<!-- frontend/views/layouts/main.php or backend/views/layouts/main.php -->
<?= $content ?>  <!-- Content renders here -->
```

### Widgets
```php
<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'columns' => ['id', 'username', 'email'],
]) ?>

<?php $form = ActiveForm::begin(); ?>
    <?= $form->field($model, 'username') ?>
<?php ActiveForm::end(); ?>
```

### Assets (per-application)
```php
namespace frontend\assets;

use yii\web\AssetBundle;

class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = ['css/site.css'];
    public $js = ['js/site.js'];
    public $depends = ['yii\web\YiiAsset'];
}
```

---

## Components

### Registration (shared in common, app-specific in {app}/config)
```php
// common/config/main.php - available to all apps
'components' => [
    'myService' => [
        'class' => 'common\components\MyService',
        'config' => 'value',
    ],
],

// frontend/config/main.php - frontend only
'components' => [
    'frontendService' => [
        'class' => 'frontend\components\FrontendService',
    ],
],

// Usage
Yii::$app->myService->doSomething();
```

### Custom Component
```php
namespace common\components;

use yii\base\Component;

class MyService extends Component
{
    public $config;

    public function init()
    {
        parent::init();
    }
}
```

### DI Container
```php
Yii::$container->set(PaymentInterface::class, StripePayment::class);
Yii::$container->setSingleton(NotificationService::class, [
    'class' => NotificationService::class,
]);
```

---

## Security

### CSRF
Enabled by default for POST/PUT/DELETE. Disable per-controller:
```php
public $enableCsrfValidation = false;
```

### SQL Injection Prevention
```php
// SAFE - always use parameter binding
User::find()->where(['username' => $username])->all();

// UNSAFE - never concatenate
User::find()->where("username = '$username'")->all();
```

### XSS Prevention
```php
<?= Html::encode($userInput) ?>
<?= HtmlPurifier::process($richText) ?>
```

### Auth
```php
Yii::$app->user->login($user);
Yii::$app->user->logout();
Yii::$app->user->isGuest;
Yii::$app->user->identity;
Yii::$app->user->can('permission');
```

---

## Performance

### Eager Loading
```php
// BAD - N+1 queries
$users = User::find()->all();
foreach ($users as $user) {
    echo $user->profile->name;
}

// GOOD - eager load
$users = User::find()->with('profile')->all();
```

### Query Optimization
```php
User::find()
    ->select(['id', 'username'])
    ->asArray()
    ->limit(10)
    ->all();
```

### Caching
```php
$data = Yii::$app->cache->getOrSet('key', function () {
    return expensiveOperation();
}, 3600);

// Query caching
User::find()->cache(3600)->all();
```

---

## Console Commands

```php
namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MyController extends Controller
{
    public function actionIndex($arg = 'default')
    {
        $this->stdout("Output\n", 32);  // Green
        return ExitCode::OK;
    }
}
```

---

## Migrations

```php
use yii\db\Migration;

class m210101_000000_create_user extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey(),
            'username' => $this->string(255)->notNull()->unique(),
            'created_at' => $this->integer()->notNull(),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%user}}');
    }
}
```

Migrations live in `console/migrations/` and run via: `php yii migrate`

---

## Anti-Patterns

```php
// WRONG: Echo in controllers
echo "Hello";
// RIGHT: Return response
return $this->render('view');

// WRONG: Business logic in views
<?php if ($post->author_id == Yii::$app->user->id): ?>
// RIGHT: Filter in controller/model

// WRONG: Ignoring save() return value
$user->save();
// RIGHT: Check return value
if (!$user->save()) {
    // handle validation errors: $user->getErrors()
}

// WRONG: Hardcode values
$email = 'admin@example.com';
// RIGHT: Use params
$email = Yii::$app->params['adminEmail'];

// WRONG: Raw SQL with variables
"SELECT * FROM user WHERE id=$id"
// RIGHT: Parameter binding
User::findOne($id);
```

---

## Key Principles

1. Always validate input via model rules
2. Use eager loading to avoid N+1 queries
3. Separate concerns: Controllers -> Models -> Views
4. Encode all output (XSS prevention)
5. Use parameter binding (SQL injection prevention)
6. Cache strategically
7. Use params for configuration values
8. Place shared code in `common/`, app-specific code in `frontend/`/`backend/`/`console/`
9. Use `-local.php` configs for environment-specific settings (never commit secrets)
10. Use `safeUp()`/`safeDown()` for transactional migrations
