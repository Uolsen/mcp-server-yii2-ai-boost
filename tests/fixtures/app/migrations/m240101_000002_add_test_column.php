<?php

use yii\db\Migration;

/**
 * Fixture migration for testing migration inspector.
 */
class m240101_000002_add_test_column extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%test_table}}', 'description', $this->text());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%test_table}}', 'description');
    }
}
