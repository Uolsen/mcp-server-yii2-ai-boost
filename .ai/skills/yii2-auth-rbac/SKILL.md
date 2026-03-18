---
name: yii2-auth-rbac
description: "Use when implementing authentication, authorization, RBAC roles and permissions, access control filters, user identity, login/logout, or REST API authentication."
version: 1.0.0
---

# Yii2 Authentication & RBAC (Advanced Template)

## User Component (per-application)
Frontend and backend have separate user components with separate sessions/cookies to allow independent login.

```php
// frontend/config/main.php
'components' => [
    'user' => [
        'identityClass' => 'common\models\User',
        'enableAutoLogin' => true,
        'identityCookie' => ['name' => '_identity-frontend', 'httpOnly' => true],
    ],
    'session' => ['name' => 'advanced-frontend'],
],

// backend/config/main.php
'components' => [
    'user' => [
        'identityClass' => 'common\models\User',
        'enableAutoLogin' => true,
        'identityCookie' => ['name' => '_identity-backend', 'httpOnly' => true],
    ],
    'session' => ['name' => 'advanced-backend'],
],
```

## User Component API
```php
// Check auth status
Yii::$app->user->isGuest;      // true if not logged in
Yii::$app->user->identity;     // Current user model or null
Yii::$app->user->id;           // Current user ID

// Login/Logout
Yii::$app->user->login($identity, $duration = 0);
Yii::$app->user->logout($destroySession = true);

// Permission check
Yii::$app->user->can('updatePost', ['post' => $post]);
```

## Identity Interface (common/models/User.php)
```php
namespace common\models;

use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

class User extends ActiveRecord implements IdentityInterface
{
    const STATUS_INACTIVE = 9;
    const STATUS_ACTIVE = 10;

    public static function tableName()
    {
        return '{{%user}}';
    }

    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return static::findOne(['access_token' => $token, 'status' => self::STATUS_ACTIVE]);
    }

    public function getId()
    {
        return $this->getPrimaryKey();
    }

    public function getAuthKey()
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    public function setPassword($password)
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    public function generateAuthKey()
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }
}
```

## RBAC Manager
```php
// common/config/main.php
'components' => [
    'authManager' => [
        'class' => 'yii\rbac\DbManager',
        // 'class' => 'yii\rbac\PhpManager',  // File-based alternative
    ],
],
```

Setup requires migration:
```bash
php yii migrate --migrationPath=@yii/rbac/migrations
```

### Creating Permissions and Roles
```php
// console/controllers/RbacController.php
namespace console\controllers;

use Yii;
use yii\console\Controller;

class RbacController extends Controller
{
    public function actionInit()
    {
        $auth = Yii::$app->authManager;
        $auth->removeAll();

        // Permissions
        $createPost = $auth->createPermission('createPost');
        $createPost->description = 'Create a post';
        $auth->add($createPost);

        $updatePost = $auth->createPermission('updatePost');
        $updatePost->description = 'Update a post';
        $auth->add($updatePost);

        $manageUsers = $auth->createPermission('manageUsers');
        $manageUsers->description = 'Manage users';
        $auth->add($manageUsers);

        // Roles
        $author = $auth->createRole('author');
        $auth->add($author);
        $auth->addChild($author, $createPost);

        $editor = $auth->createRole('editor');
        $auth->add($editor);
        $auth->addChild($editor, $author);  // inherits author
        $auth->addChild($editor, $updatePost);

        $admin = $auth->createRole('admin');
        $auth->add($admin);
        $auth->addChild($admin, $editor);   // inherits editor
        $auth->addChild($admin, $manageUsers);

        // Assign role to user
        $auth->assign($admin, 1);  // user ID 1 is admin
    }
}
```

### RBAC Rules
```php
namespace common\rbac;

use yii\rbac\Rule;

class AuthorRule extends Rule
{
    public $name = 'isAuthor';

    public function execute($user, $item, $params)
    {
        return isset($params['post']) ? $params['post']->user_id == $user : false;
    }
}

// Attach rule to permission
$rule = new AuthorRule();
$auth->add($rule);

$updateOwnPost = $auth->createPermission('updateOwnPost');
$updateOwnPost->description = 'Update own post';
$updateOwnPost->ruleName = $rule->name;
$auth->add($updateOwnPost);
$auth->addChild($updateOwnPost, $updatePost);
$auth->addChild($author, $updateOwnPost);
```

## Access Control Filter
```php
public function behaviors()
{
    return [
        'access' => [
            'class' => AccessControl::class,
            'rules' => [
                ['actions' => ['login'], 'allow' => true, 'roles' => ['?']], // Guests only
                ['actions' => ['logout'], 'allow' => true, 'roles' => ['@']], // Authenticated
                ['allow' => true, 'roles' => ['admin']],  // RBAC role check
            ],
        ],
    ];
}
```

## REST API Authentication
```php
// For API controllers
public function behaviors()
{
    $behaviors = parent::behaviors();
    $behaviors['authenticator'] = [
        'class' => \yii\filters\auth\CompositeAuth::class,
        'authMethods' => [
            \yii\filters\auth\HttpBearerAuth::class,
            \yii\filters\auth\QueryParamAuth::class,
        ],
    ];
    return $behaviors;
}
```
