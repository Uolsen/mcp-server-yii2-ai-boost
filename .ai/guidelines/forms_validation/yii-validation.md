# Yii2 Validation (Advanced Template)

## Model Placement for Form Models
- **`common/models/`** - Shared form models (LoginForm used by both frontend and backend)
- **`frontend/models/`** - Frontend forms (SignupForm, ContactForm, PasswordResetRequestForm)
- **`backend/models/`** - Backend forms (admin-specific search/filter models)

## Common Validators
```php
public function rules()
{
    return [
        // Required
        [['username', 'email'], 'required'],

        // Type validators
        ['age', 'integer'],
        ['price', 'number'],           // float/decimal
        ['active', 'boolean'],
        ['name', 'string', 'min' => 2, 'max' => 255],

        // Format validators
        ['email', 'email'],
        ['website', 'url'],
        ['birth_date', 'date', 'format' => 'php:Y-m-d'],
        ['ip_address', 'ip'],

        // File validators
        ['avatar', 'file', 'extensions' => ['png', 'jpg'], 'maxSize' => 1024 * 1024],
        ['photo', 'image', 'minWidth' => 100, 'maxWidth' => 2000],

        // Comparison
        ['password_repeat', 'compare', 'compareAttribute' => 'password'],
        ['age', 'compare', 'compareValue' => 18, 'operator' => '>='],

        // Range
        ['status', 'in', 'range' => [1, 2, 3]],

        // Pattern
        ['phone', 'match', 'pattern' => '/^\+?[0-9]{10,15}$/'],

        // Database
        ['email', 'unique'],
        ['email', 'unique', 'targetClass' => User::class, 'message' => 'This email is taken.'],
        ['category_id', 'exist', 'targetClass' => Category::class, 'targetAttribute' => 'id'],

        // Filter (transforms value before validation)
        ['username', 'filter', 'filter' => 'trim'],
        ['email', 'filter', 'filter' => 'strtolower'],

        // Safe (mass assignment only, no validation)
        ['description', 'safe'],

        // Custom inline validator
        ['field', 'validateCustom'],

        // Conditional validation
        ['phone', 'required', 'when' => function ($model) {
            return $model->contact_method === 'phone';
        }, 'whenClient' => "function(attribute, value) {
            return $('#contactform-contact_method').val() === 'phone';
        }"],
    ];
}
```

## Custom Inline Validator
```php
public function validateCustom($attribute, $params)
{
    if ($this->$attribute === 'invalid') {
        $this->addError($attribute, 'Value is invalid.');
    }
}
```

## Standalone Validator Class
```php
namespace common\validators;

use yii\validators\Validator;

class PhoneValidator extends Validator
{
    public $countryCode = 'US';

    public function validateAttribute($model, $attribute)
    {
        $value = $model->$attribute;
        if (!preg_match('/^\+?[0-9]{10,15}$/', $value)) {
            $this->addError($model, $attribute, '{attribute} is not a valid phone number.');
        }
    }
}

// Usage in rules()
['phone', 'common\validators\PhoneValidator', 'countryCode' => 'CZ'],
```

## Validation Usage
```php
// Standard load + validate + save pattern
$model = new User();
if ($model->load(Yii::$app->request->post()) && $model->save()) {
    return $this->redirect(['view', 'id' => $model->id]);
}

// Validate without saving
if ($model->validate()) {
    // all good
}

// Validate specific attributes only
$model->validate(['email', 'username']);

// Get errors
$model->getErrors();           // All errors: ['email' => ['Email is invalid.'], ...]
$model->getFirstErrors();      // First error per attribute: ['email' => 'Email is invalid.']
$model->getFirstError('email'); // First error for specific attribute
$model->hasErrors();           // Has any errors
$model->hasErrors('email');    // Has errors for specific attribute
```

## Scenarios
```php
public function scenarios()
{
    return [
        'register' => ['username', 'email', 'password'],
        'update' => ['username', 'email'],
        'admin-update' => ['username', 'email', 'status', 'role'],
    ];
}

// Set scenario before load
$model->scenario = 'register';
$model->load(Yii::$app->request->post());
$model->save();

// Rules can be scenario-specific
['password', 'required', 'on' => 'register'],
['status', 'required', 'on' => 'admin-update'],
['password', 'string', 'min' => 8, 'except' => 'update'],
```

## Attribute Labels
```php
public function attributeLabels()
{
    return [
        'username' => Yii::t('app', 'Username'),
        'email' => Yii::t('app', 'Email Address'),
        'created_at' => Yii::t('app', 'Created At'),
    ];
}
```
