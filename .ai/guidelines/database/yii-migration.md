# Yii2 Database Migration (Advanced Template)

## Migration Location
Migrations live in `console/migrations/` and are run from the console application.

## Migration Class API
```php
namespace yii\db;

class Migration extends Component
{
    public $db = 'db';

    // Table operations
    public function createTable($table, $columns, $options = null);
    public function dropTable($table);
    public function renameTable($table, $newName);
    public function truncateTable($table);

    // Column operations
    public function addColumn($table, $column, $type);
    public function dropColumn($table, $column);
    public function renameColumn($table, $name, $newName);
    public function alterColumn($table, $column, $type);

    // Index operations
    public function createIndex($name, $table, $columns, $unique = false);
    public function dropIndex($name, $table);

    // Foreign key operations
    public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null);
    public function dropForeignKey($name, $table);

    // Data operations
    public function insert($table, $columns);
    public function batchInsert($table, $columns, $rows);
    public function update($table, $columns, $condition = '', $params = []);
    public function delete($table, $condition = '', $params = []);
    public function execute($sql, $params = []);

    // Column types
    public function primaryKey($length = null);
    public function bigPrimaryKey($length = null);
    public function string($length = null);   // VARCHAR(255)
    public function text();                    // TEXT
    public function integer($length = null);   // INT
    public function bigInteger($length = null);// BIGINT
    public function boolean();                 // TINYINT(1)
    public function float($precision = null);
    public function decimal($precision = null, $scale = null);
    public function dateTime($precision = null);
    public function timestamp($precision = null);
    public function date();
    public function binary($length = null);
    public function json();
}
```

## Recommended: Transactional Migrations (safeUp/safeDown)
```php
use yii\db\Migration;

class m210101_000000_create_user extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey(),
            'username' => $this->string(255)->notNull()->unique(),
            'email' => $this->string(255)->notNull(),
            'password_hash' => $this->string(255)->notNull(),
            'auth_key' => $this->string(32)->notNull(),
            'status' => $this->smallInteger()->notNull()->defaultValue(10),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx-user-email', '{{%user}}', 'email', true);
    }

    public function safeDown()
    {
        $this->dropTable('{{%user}}');
    }
}
```
`safeUp()`/`safeDown()` wrap the migration in a transaction automatically. Prefer these over `up()`/`down()`.

## Foreign Keys Example
```php
public function safeUp()
{
    $this->createTable('{{%post}}', [
        'id' => $this->primaryKey(),
        'user_id' => $this->integer()->notNull(),
        'title' => $this->string(255)->notNull(),
        'body' => $this->text(),
        'created_at' => $this->integer()->notNull(),
    ]);

    $this->addForeignKey(
        'fk-post-user_id',
        '{{%post}}',
        'user_id',
        '{{%user}}',
        'id',
        'CASCADE',  // ON DELETE
        'CASCADE'   // ON UPDATE
    );

    $this->createIndex('idx-post-user_id', '{{%post}}', 'user_id');
}

public function safeDown()
{
    $this->dropForeignKey('fk-post-user_id', '{{%post}}');
    $this->dropTable('{{%post}}');
}
```

## Data Seeding
```php
public function safeUp()
{
    $this->batchInsert('{{%status}}', ['id', 'name'], [
        [1, 'Active'],
        [2, 'Inactive'],
        [3, 'Banned'],
    ]);
}
```

## CLI Commands
```bash
php yii migrate                         # Apply all pending migrations
php yii migrate/down                    # Revert last migration
php yii migrate/down 3                  # Revert last 3 migrations
php yii migrate/create create_post      # Create new migration
php yii migrate/history                 # Show applied migrations
php yii migrate/new                     # Show pending migrations
php yii migrate/redo                    # Revert and re-apply last migration

# Apply RBAC migrations (common need)
php yii migrate --migrationPath=@yii/rbac/migrations
```

## Multiple Migration Paths
```php
// console/config/main.php
'controllerMap' => [
    'migrate' => [
        'class' => 'yii\console\controllers\MigrateController',
        'migrationPath' => null, // disable default path
        'migrationNamespaces' => [
            'console\migrations',
            'common\migrations',
        ],
    ],
],
```
