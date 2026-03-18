---
name: yii2-helpers
description: "Use when working with Yii2 helper classes like ArrayHelper, Html, Url, Json, StringHelper, FileHelper, Inflector, or VarDumper."
version: 1.0.0
---

# Yii2 Helper Classes (Advanced Template)

Helpers are static utility classes. Available everywhere regardless of app tier.

## ArrayHelper
```php
use yii\helpers\ArrayHelper;

// Get value with dot notation (safe nested access)
ArrayHelper::getValue($array, 'user.name', 'default');
ArrayHelper::getValue($model, 'profile.avatar');

// Build key-value map from objects/arrays
ArrayHelper::map($models, 'id', 'name');
// Result: [1 => 'John', 2 => 'Jane']

// Group by key
ArrayHelper::map($models, 'id', 'name', 'department');
// Result: ['Engineering' => [1 => 'John'], 'Sales' => [2 => 'Jane']]

// Index array by key
ArrayHelper::index($models, 'id');

// Get column values
ArrayHelper::getColumn($models, 'name');
ArrayHelper::getColumn($models, function ($model) {
    return $model->firstName . ' ' . $model->lastName;
});

// Merge arrays recursively (later values override)
ArrayHelper::merge($array1, $array2);

// Check if key exists (case-insensitive option)
ArrayHelper::keyExists('key', $array, $caseSensitive = true);

// Remove and return element
$value = ArrayHelper::remove($array, 'key', $default);

// Convert object to array
ArrayHelper::toArray($models, [
    'common\models\User' => ['id', 'username', 'email'],
]);

// Check if array is associative or indexed
ArrayHelper::isAssociative($array);
ArrayHelper::isIndexed($array);
```

## Url Helper
```php
use yii\helpers\Url;

// Create URL (route-based)
Url::to(['site/index']);                     // /site/index
Url::to(['post/view', 'id' => 1]);          // /post/view?id=1
Url::to(['post/view', 'id' => 1], true);    // http://example.com/post/view?id=1
Url::to(['post/view', 'id' => 1], 'https'); // https://example.com/post/view?id=1

// Current URL with modified params
Url::current();
Url::current(['page' => 2]);

// Home URL
Url::home();
Url::home('https');

// Remember and retrieve URL (useful for return-after-login)
Url::remember(['post/index'], 'post-listing');
$url = Url::previous('post-listing');
```

## Html Helper
```php
use yii\helpers\Html;

// XSS-safe output (always use for user input)
Html::encode($text);
Html::decode($text);

// Links
Html::a('Click', ['site/index']);
Html::a('Click', ['site/index'], ['class' => 'btn btn-primary']);
Html::mailto('Email', 'user@example.com');

// Images
Html::img('@web/images/logo.png', ['alt' => 'Logo']);

// Tags
Html::tag('div', 'content', ['class' => 'box', 'id' => 'main']);
Html::beginTag('div', ['class' => 'wrapper']);
Html::endTag('div');

// Lists
Html::ul(['item1', 'item2'], ['class' => 'list']);
Html::ol(['first', 'second']);

// Form elements
Html::input('text', 'name', 'value', ['class' => 'form-control']);
Html::dropDownList('status', $selected, [1 => 'Active', 0 => 'Inactive']);
Html::checkbox('agree', false, ['label' => 'I agree']);

// CSS/JS
Html::cssFile('@web/css/style.css');
Html::jsFile('@web/js/app.js');
Html::style('body { color: red; }');
Html::script('alert("hello")');
```

## Json Helper
```php
use yii\helpers\Json;

Json::encode($data);                  // Throws on error (unlike json_encode)
Json::decode($json);                  // Throws on error
Json::decode($json, true);            // As associative array
Json::htmlEncode($data);              // Safe for embedding in HTML attributes
```

## StringHelper
```php
use yii\helpers\StringHelper;

StringHelper::truncate($string, 100);                // Truncate with '...'
StringHelper::truncate($string, 100, '---');          // Custom suffix
StringHelper::truncateWords($string, 20);             // Truncate by word count
StringHelper::startsWith($string, 'prefix');
StringHelper::endsWith($string, 'suffix');
StringHelper::basename('common\models\User');          // 'User'
StringHelper::dirname('common\models\User');           // 'common\models'
```

## FileHelper
```php
use yii\helpers\FileHelper;

FileHelper::createDirectory($path, $mode = 0775);
FileHelper::removeDirectory($path);
FileHelper::copyDirectory($src, $dst);
FileHelper::findFiles($dir, ['only' => ['*.php']]);
FileHelper::findDirectories($dir);
FileHelper::getMimeType($file);
FileHelper::normalizePath($path);
```

## VarDumper
```php
use yii\helpers\VarDumper;

VarDumper::dump($var, $depth = 10, $highlight = true);  // Pretty print
VarDumper::export($var);                                  // PHP-exportable string
```

## Inflector
```php
use yii\helpers\Inflector;

Inflector::camelize('my_variable');      // 'MyVariable'
Inflector::camel2id('PostComment');      // 'post-comment'
Inflector::pluralize('post');            // 'posts'
Inflector::singularize('posts');         // 'post'
Inflector::slug('Hello World!');         // 'hello-world'
Inflector::humanize('my_var_name');      // 'My Var Name'
Inflector::titleize('hello world');      // 'Hello World'
```
