# Yii2 Views & Templating (Advanced Template)

## View Placement
- **`frontend/views/`** - Frontend views and layouts
- **`backend/views/`** - Backend views and layouts
- **`common/mail/`** - Shared email templates
- **`common/widgets/`** - Shared widget views

Each app has its own `views/layouts/main.php` (frontend typically uses a public layout, backend uses an admin layout).

## View Class API
```php
namespace yii\web;

class View extends \yii\base\View
{
    public $title;           // Page title
    public $params = [];     // Shared params (breadcrumbs, meta, etc.)

    // Asset registration
    public function registerMetaTag($options, $key = null);
    public function registerLinkTag($options, $key = null);
    public function registerCss($css, $options = [], $key = null);
    public function registerCssFile($url, $options = [], $key = null);
    public function registerJs($js, $position = self::POS_READY, $key = null);
    public function registerJsFile($url, $options = [], $key = null);

    // JS positions
    const POS_HEAD = 1;    // In <head>
    const POS_BEGIN = 2;   // Start of <body>
    const POS_END = 3;     // End of <body>
    const POS_READY = 4;   // jQuery $(document).ready()
    const POS_LOAD = 5;    // window.onload
}
```

## View Files
```php
<!-- frontend/views/site/index.php -->
<?php
use yii\helpers\Html;

$this->title = 'Home Page';
$this->params['breadcrumbs'][] = 'Home';

// Register meta tags
$this->registerMetaTag(['name' => 'description', 'content' => 'My site description']);
?>

<h1><?= Html::encode($this->title) ?></h1>
<p><?= Html::encode($message) ?></p>

<?= Html::a('View Post', ['post/view', 'id' => 1], ['class' => 'btn btn-primary']) ?>
<?= Html::img('@web/images/logo.png', ['alt' => 'Logo']) ?>
```

## Layouts

### Frontend Layout (`frontend/views/layouts/main.php`)
```php
<?php
use frontend\assets\AppAsset;
use yii\helpers\Html;
use yii\widgets\Breadcrumbs;

AppAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= Html::csrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>

<header>
    <!-- Navigation -->
</header>

<main>
    <?= Breadcrumbs::widget([
        'links' => isset($this->params['breadcrumbs']) ? $this->params['breadcrumbs'] : [],
    ]) ?>
    <?= $content ?>
</main>

<footer>
    <p>&copy; <?= date('Y') ?> My Company</p>
</footer>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
```

### Backend Layout (`backend/views/layouts/main.php`)
Same structure but with admin-specific navigation, sidebar, dashboard layout.

## Asset Bundles (per-application)
```php
namespace frontend\assets;

use yii\web\AssetBundle;

class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = ['css/site.css'];
    public $js = ['js/site.js'];
    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
    ];
}
```

## Widgets

### ActiveForm
```php
<?php use yii\widgets\ActiveForm; ?>
<?php use yii\helpers\Html; ?>

<?php $form = ActiveForm::begin(['id' => 'contact-form']); ?>
    <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'email') ?>
    <?= $form->field($model, 'body')->textarea(['rows' => 6]) ?>
    <?= $form->field($model, 'category')->dropDownList($categories, ['prompt' => 'Select...']) ?>
    <?= $form->field($model, 'agree')->checkbox() ?>
    <div class="form-group">
        <?= Html::submitButton('Submit', ['class' => 'btn btn-primary']) ?>
    </div>
<?php ActiveForm::end(); ?>
```

### GridView (commonly used in backend)
```php
<?php use yii\grid\GridView; ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        'id',
        'username',
        'email:email',
        'created_at:datetime',
        [
            'attribute' => 'status',
            'value' => function ($model) {
                return $model->status === 10 ? 'Active' : 'Inactive';
            },
            'filter' => [10 => 'Active', 9 => 'Inactive'],
        ],
        [
            'class' => 'yii\grid\ActionColumn',
            'template' => '{view} {update} {delete}',
        ],
    ],
]) ?>
```

### ListView
```php
<?php use yii\widgets\ListView; ?>

<?= ListView::widget([
    'dataProvider' => $dataProvider,
    'itemView' => '_post',           // renders frontend/views/{controller}/_post.php
    'summary' => 'Showing {begin}-{end} of {totalCount}',
    'pager' => ['class' => 'yii\widgets\LinkPager'],
]) ?>
```

### DetailView
```php
<?php use yii\widgets\DetailView; ?>

<?= DetailView::widget([
    'model' => $model,
    'attributes' => [
        'id',
        'username',
        'email:email',
        'created_at:datetime',
        [
            'attribute' => 'status',
            'value' => $model->status === 10 ? 'Active' : 'Inactive',
        ],
    ],
]) ?>
```

### Pjax (AJAX page updates)
```php
<?php use yii\widgets\Pjax; ?>

<?php Pjax::begin(['id' => 'posts-pjax']); ?>
    <?= GridView::widget([...]) ?>
<?php Pjax::end(); ?>
```

## Content Blocks
```php
<!-- In view -->
<?php $this->beginBlock('sidebar'); ?>
    <p>Sidebar content</p>
<?php $this->endBlock(); ?>

<!-- In layout -->
<?php if (isset($this->blocks['sidebar'])): ?>
    <aside><?= $this->blocks['sidebar'] ?></aside>
<?php endif; ?>
```

## Html Helper Quick Reference
```php
use yii\helpers\Html;

Html::encode($text);                     // XSS-safe output
Html::a($text, $url, $options);          // Link
Html::img($src, $options);               // Image
Html::tag($name, $content, $options);    // Generic tag
Html::beginTag($name, $options);         // Open tag
Html::endTag($name);                     // Close tag
Html::ul($items, $options);              // Unordered list
Html::ol($items, $options);              // Ordered list
Html::submitButton($label, $options);    // Submit button
Html::dropDownList($name, $sel, $items); // Select dropdown
Html::checkbox($name, $checked);         // Checkbox
Html::radio($name, $checked);            // Radio button
```
