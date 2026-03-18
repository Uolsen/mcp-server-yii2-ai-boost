---
name: yii2-cache
description: "Use when implementing caching, cache backends, cache dependencies, query caching, fragment caching, or cache invalidation in Yii2."
version: 1.0.0
---

# Yii2 Caching (Advanced Template)

## Configuration
Cache is typically configured in `common/config/main.php` so all applications share the same cache backend.

```php
// common/config/main.php
'components' => [
    'cache' => [
        'class' => 'yii\caching\FileCache',
        'cachePath' => '@runtime/cache',  // per-app runtime directory
    ],
],
```

For shared cache across apps, use a centralized backend:
```php
// common/config/main.php
'components' => [
    'cache' => [
        'class' => 'yii\redis\Cache',  // requires yii2-redis
        'redis' => [
            'hostname' => 'localhost',
            'port' => 6379,
            'database' => 0,
        ],
    ],
],
```

## Cache Class API
```php
namespace yii\caching;

abstract class Cache extends Component
{
    public function get($key);                                      // Get cached value or false
    public function set($key, $value, $duration = 0, $dep = null);  // Store value
    public function add($key, $value, $duration = 0, $dep = null);  // Store if not exists
    public function delete($key);                                    // Delete value
    public function flush();                                         // Clear all cache
    public function exists($key);                                    // Check if key exists

    // Recommended: get or compute
    public function getOrSet($key, $callable, $duration = null, $dependency = null);
}
```

## Backends
- `FileCache` - File-based (default, per-app `@runtime/cache`)
- `MemCache` - Memcached (requires PHP memcache/memcached extension)
- `yii\redis\Cache` - Redis (requires `yiisoft/yii2-redis`)
- `DbCache` - Database-backed
- `DummyCache` - No-op for testing
- `ArrayCache` - In-memory, single-request only

## Usage Patterns

### Simple get/set
```php
$data = Yii::$app->cache->get('key');
if ($data === false) {
    $data = expensiveOperation();
    Yii::$app->cache->set('key', $data, 3600); // 1 hour
}
```

### Preferred: getOrSet
```php
$data = Yii::$app->cache->getOrSet('key', function () {
    return expensiveOperation();
}, 3600);
```

### Query Caching
```php
$users = User::find()->cache(3600)->all();

// With dependency
$users = User::find()
    ->cache(3600, new DbDependency(['sql' => 'SELECT MAX(updated_at) FROM {{%user}}']))
    ->all();
```

### Cache Dependencies
```php
use yii\caching\DbDependency;
use yii\caching\TagDependency;
use yii\caching\FileDependency;
use yii\caching\ExpressionDependency;

// DB dependency - invalidates when query result changes
$dep = new DbDependency(['sql' => 'SELECT MAX(updated_at) FROM {{%post}}']);
Yii::$app->cache->set('posts', $posts, 3600, $dep);

// Tag dependency - invalidate by tag name
TagDependency::invalidate(Yii::$app->cache, 'posts');
Yii::$app->cache->set('posts', $posts, 0, new TagDependency(['tags' => ['posts']]));

// File dependency
$dep = new FileDependency(['fileName' => '@common/data/config.json']);
```

### Fragment Caching (in views)
```php
<?php if ($this->beginCache('sidebar', ['duration' => 3600])): ?>
    <!-- expensive content -->
    <?= $this->render('_sidebar') ?>
<?php $this->endCache(); endif; ?>
```
