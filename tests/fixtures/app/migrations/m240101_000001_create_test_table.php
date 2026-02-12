<?php

use yii\db\Migration;

/**
 * Fixture migration for testing migration inspector.
 */
class m240101_000001_create_test_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%test_table}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'created_at' => $this->integer()->notNull(),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%test_table}}');
    }
}
