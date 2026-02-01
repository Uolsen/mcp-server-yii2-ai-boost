<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\fixtures;

/**
 * Trait for setting up SQLite test schema.
 *
 * Use in setUp() of tests that need database tables.
 */
trait SchemaSetupTrait
{
    /**
     * Create the test database tables in SQLite.
     */
    protected function createTestSchema(): void
    {
        $db = \Yii::$app->db;

        $db->createCommand()->createTable('user', [
            'id' => 'pk',
            'username' => 'string(255) NOT NULL',
            'email' => 'string(255) NOT NULL',
            'password_hash' => 'string(255) NOT NULL',
            'status' => 'smallint NOT NULL DEFAULT 10',
            'created_at' => 'integer NOT NULL',
            'updated_at' => 'integer NOT NULL',
        ])->execute();

        $db->createCommand()->createTable('post', [
            'id' => 'pk',
            'title' => 'string(255) NOT NULL',
            'body' => 'text NOT NULL',
            'user_id' => 'integer NOT NULL',
            'category_id' => 'integer',
            'status' => 'smallint NOT NULL DEFAULT 0',
            'created_at' => 'integer',
        ])->execute();

        $db->createCommand()->createTable('category', [
            'id' => 'pk',
            'name' => 'string(255) NOT NULL',
            'description' => 'text',
        ])->execute();
    }

    /**
     * Drop all test tables.
     */
    protected function dropTestSchema(): void
    {
        $db = \Yii::$app->db;
        $db->createCommand()->dropTable('post')->execute();
        $db->createCommand()->dropTable('user')->execute();
        $db->createCommand()->dropTable('category')->execute();
    }
}
