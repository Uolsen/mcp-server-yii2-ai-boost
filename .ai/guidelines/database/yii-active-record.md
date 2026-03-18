# Yii2 Active Record (Advanced Template)

## Model Placement
- **`common/models/`** - Shared AR models used by multiple apps (User, Post, Category)
- **`frontend/models/`** - Frontend-only models (SignupForm, search models)
- **`backend/models/`** - Backend-only models (admin search models, report models)

## Class Definition
```php
namespace common\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

class User extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%user}}';
    }

    public static function primaryKey()
    {
        return ['id'];
    }

    public function rules()
    {
        return [
            [['username', 'email'], 'required'],
            ['email', 'email'],
            ['status', 'integer'],
            ['username', 'string', 'max' => 255],
            ['username', 'unique'],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE]],
        ];
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    // Relations
    public function getProfile()
    {
        return $this->hasOne(Profile::class, ['user_id' => 'id']);
    }

    public function getPosts()
    {
        return $this->hasMany(Post::class, ['user_id' => 'id']);
    }

    // Inverse relation
    public function getComments()
    {
        return $this->hasMany(Comment::class, ['user_id' => 'id'])
            ->inverseOf('user');
    }
}
```

## CRUD Operations
```php
// Find
$user = User::findOne(1);
$user = User::findOne(['email' => 'test@example.com']);
$users = User::find()->where(['status' => 1])->all();

// Eager loading (avoid N+1)
$users = User::find()->with('profile', 'posts')->all();
$users = User::find()->joinWith('profile')->where(['profile.active' => 1])->all();

// Query builder
User::find()
    ->select(['id', 'username'])
    ->where(['status' => 1])
    ->andWhere(['>', 'created_at', $date])
    ->orderBy(['created_at' => SORT_DESC])
    ->limit(10)
    ->offset(20)
    ->asArray()
    ->all();

// Save (calls validate() internally, check return value)
$user = new User();
$user->username = 'john';
if (!$user->save()) {
    // Handle errors: $user->getErrors()
}

// Update single record
$user->updateAttributes(['status' => 2]);

// Batch update
User::updateAll(['status' => 0], ['<', 'last_login', $expiry]);

// Delete
$user->delete();
User::deleteAll(['status' => 0]);
```

## Transactions
```php
$transaction = Yii::$app->db->beginTransaction();
try {
    $user->save();
    $profile->user_id = $user->id;
    $profile->save();
    $transaction->commit();
} catch (\Exception $e) {
    $transaction->rollBack();
    throw $e;
}
```

## Lifecycle Callbacks
```php
public function beforeSave($insert)
{
    if (!parent::beforeSave($insert)) {
        return false;
    }
    if ($insert) {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }
    return true;
}

public function afterSave($insert, $changedAttributes)
{
    parent::afterSave($insert, $changedAttributes);
    // e.g., invalidate cache, send notification
}
```

## Relational Data Management
```php
// Link/unlink related records
$post = new Post(['title' => 'New Post']);
$user->link('posts', $post);     // Sets user_id and saves
$user->unlink('posts', $post);   // Removes relation

// Many-to-many via junction table
public function getTags()
{
    return $this->hasMany(Tag::class, ['id' => 'tag_id'])
        ->viaTable('{{%post_tag}}', ['post_id' => 'id']);
}
```

## Batch Processing
```php
// Memory-efficient iteration over large datasets
foreach (User::find()->where(['status' => 1])->batch(100) as $users) {
    // $users is array of 100 User models
}

foreach (User::find()->where(['status' => 1])->each(100) as $user) {
    // $user is a single User model
}
```
