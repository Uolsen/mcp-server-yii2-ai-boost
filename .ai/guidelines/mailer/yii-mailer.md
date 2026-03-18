# Yii2 Mailer (Advanced Template)

Uses `yii2-symfonymailer` (Symfony Mailer). The older `yii2-swiftmailer` is deprecated and abandoned.

## Installation
```bash
composer require yiisoft/yii2-symfonymailer
```

## Configuration
Mailer is typically configured in `common/config/main.php` (shared) with credentials in `common/config/main-local.php` (gitignored).

```php
// common/config/main.php
'components' => [
    'mailer' => [
        'class' => 'yii\symfonymailer\Mailer',
        'viewPath' => '@common/mail',
        'useFileTransport' => false,  // true in dev = saves to @runtime/mail
    ],
],

// common/config/main-local.php (environment-specific, gitignored)
'components' => [
    'mailer' => [
        'transport' => [
            'scheme' => 'smtps',
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
        ],
    ],
],
```

### Development Configuration
```php
// environments/dev/common/config/main-local.php
'components' => [
    'mailer' => [
        'useFileTransport' => true, // Saves emails to @runtime/mail instead of sending
    ],
],
```

## Sending Email
```php
// Simple text email
Yii::$app->mailer->compose()
    ->setFrom('noreply@example.com')
    ->setTo('user@example.com')
    ->setSubject('Subject')
    ->setTextBody('Plain text content')
    ->send();

// HTML email with view template (from @common/mail/)
Yii::$app->mailer->compose('welcome', ['user' => $user])
    ->setFrom(Yii::$app->params['senderEmail'])
    ->setTo($user->email)
    ->setSubject('Welcome to Our Site')
    ->send();

// Both HTML and text versions
Yii::$app->mailer->compose([
    'html' => 'password-reset-html',
    'text' => 'password-reset-text',
], ['user' => $user])
    ->setFrom(Yii::$app->params['senderEmail'])
    ->setTo($user->email)
    ->setSubject('Password Reset')
    ->send();

// With attachment
Yii::$app->mailer->compose()
    ->setTo('user@example.com')
    ->setSubject('Report')
    ->setTextBody('See attached report.')
    ->attach('/path/to/file.pdf')
    ->attachContent($csvContent, [
        'fileName' => 'report.csv',
        'contentType' => 'text/csv',
    ])
    ->send();
```

## Email View Templates
Mail templates live in `common/mail/` (shared across apps):

```
common/
  mail/
    layouts/
      html.php          # HTML mail layout
      text.php          # Text mail layout
    welcome.php         # HTML welcome email
    password-reset-html.php
    password-reset-text.php
```

### HTML Mail Layout (`common/mail/layouts/html.php`)
```php
<?php use yii\helpers\Html; ?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="<?= Yii::$app->charset ?>">
</head>
<body>
    <?php $this->beginBody() ?>
    <?= $content ?>
    <?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
```

### Email Template (`common/mail/welcome.php`)
```php
<?php use yii\helpers\Html; ?>
<p>Hello <?= Html::encode($user->username) ?>,</p>
<p>Welcome to our application.</p>
```

## Checking Send Result
```php
$result = Yii::$app->mailer->compose('welcome', ['user' => $user])
    ->setFrom(Yii::$app->params['senderEmail'])
    ->setTo($user->email)
    ->setSubject('Welcome')
    ->send();

if (!$result) {
    Yii::error('Failed to send welcome email to ' . $user->email, 'common\mail');
}
```
