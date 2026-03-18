# Yii2 Internationalization (Advanced Template)

## Message Translation
```php
// Basic translation
Yii::t('app', 'Hello');

// With parameters
Yii::t('app', 'Welcome, {name}', ['name' => 'John']);

// Pluralization (ICU MessageFormat)
Yii::t('app', '{n, plural, =0{No posts} =1{One post} other{# posts}}', ['n' => $count]);

// Number formatting in translations
Yii::t('app', 'Price: {price, number, currency}', ['price' => 1234.56]);
```

## Configuration (Advanced Template)
Translations are typically configured in `common/config/main.php` for shared message sources:

```php
// common/config/main.php
'components' => [
    'i18n' => [
        'translations' => [
            'app*' => [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@common/messages',
            ],
            'frontend*' => [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@frontend/messages',
            ],
            'backend*' => [
                'class' => 'yii\i18n\PhpMessageSource',
                'basePath' => '@backend/messages',
            ],
        ],
    ],
],
```

### Message Source Types
```php
// PHP file source (most common)
'class' => 'yii\i18n\PhpMessageSource',

// Database source
'class' => 'yii\i18n\DbMessageSource',

// Gettext source
'class' => 'yii\i18n\GettextMessageSource',
```

## Message Files Structure
```
common/
  messages/
    de/
      app.php           # Shared translations
    cs/
      app.php
frontend/
  messages/
    de/
      frontend.php      # Frontend-only translations
backend/
  messages/
    de/
      backend.php       # Backend-only translations
```

### Message File Content
```php
// common/messages/de/app.php
return [
    'Hello' => 'Hallo',
    'Welcome, {name}' => 'Willkommen, {name}',
    '{n, plural, =0{No posts} =1{One post} other{# posts}}' =>
        '{n, plural, =0{Keine Beiträge} =1{Ein Beitrag} other{# Beiträge}}',
];
```

## Message Extraction
```bash
# Generate config
php yii message/config @common/messages/config.php

# Extract messages from source code
php yii message/extract @common/messages/config.php
```

## Formatter
```php
$formatter = Yii::$app->formatter;

// Date/Time
$formatter->asDate($timestamp);            // Jan 1, 2024
$formatter->asDate($timestamp, 'long');    // January 1, 2024
$formatter->asDatetime($timestamp);        // Jan 1, 2024 12:00 PM
$formatter->asRelativeTime($timestamp);    // 2 hours ago
$formatter->asTime($timestamp);            // 12:00 PM

// Numbers
$formatter->asDecimal(1234.56);            // 1,234.56
$formatter->asCurrency(1234.56, 'USD');    // $1,234.56
$formatter->asCurrency(1234.56, 'CZK');   // 1 234,56 Kc
$formatter->asPercent(0.75);               // 75%
$formatter->asInteger(1234);               // 1,234

// Size
$formatter->asShortSize(1024 * 1024);      // 1 MB

// Other
$formatter->asBoolean(true);               // Yes
$formatter->asEmail('user@example.com');   // Clickable mailto link
$formatter->asNtext("line1\nline2");       // With <br> tags
$formatter->asText($html);                 // Strip tags
$formatter->asRaw($html);                  // No encoding (use with care)
```

## Set Locale
```php
// In application config
'language' => 'cs-CZ',           // Target language
'sourceLanguage' => 'en-US',     // Source language of code

// At runtime
Yii::$app->language = 'de-DE';

// Formatter follows app language automatically
Yii::$app->formatter->locale = 'de-DE'; // Override if needed
```
