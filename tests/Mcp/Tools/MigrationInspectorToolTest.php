<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\MigrationInspectorTool;

class MigrationInspectorToolTest extends ToolTestCase
{
    private MigrationInspectorTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new MigrationInspectorTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);

        $this->createMigrationTable();
    }

    protected function tearDown(): void
    {
        $this->dropMigrationTable();
        parent::tearDown();
    }

    public function testGetName(): void
    {
        $this->assertSame('migration_inspector', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('migration', $schema['properties']);
        $this->assertArrayHasKey('include', $schema['properties']);
        $this->assertArrayHasKey('limit', $schema['properties']);
    }

    public function testGetStatus(): void
    {
        $result = $this->tool->execute(['include' => ['status']]);

        $this->assertArrayHasKey('status', $result);
        $status = $result['status'];
        $this->assertArrayHasKey('total', $status);
        $this->assertArrayHasKey('applied', $status);
        $this->assertArrayHasKey('pending', $status);
        $this->assertArrayHasKey('migration_paths', $status);
        $this->assertArrayHasKey('migration_table', $status);
        $this->assertIsInt($status['total']);
        $this->assertIsInt($status['applied']);
        $this->assertIsInt($status['pending']);
    }

    public function testGetStatusWithAppliedMigrations(): void
    {
        $this->insertAppliedMigration('m240101_000001_create_test_table', time() - 3600);

        $result = $this->tool->execute(['include' => ['status']]);
        $status = $result['status'];

        $this->assertGreaterThanOrEqual(1, $status['applied']);
        $this->assertArrayHasKey('last_applied', $status);
        $this->assertSame('m240101_000001_create_test_table', $status['last_applied']['version']);
    }

    public function testGetHistory(): void
    {
        $time1 = time() - 7200;
        $time2 = time() - 3600;
        $this->insertAppliedMigration('m240101_000001_create_test_table', $time1);
        $this->insertAppliedMigration('m240101_000002_add_test_column', $time2);

        $result = $this->tool->execute(['include' => ['history']]);

        $this->assertArrayHasKey('history', $result);
        $this->assertCount(2, $result['history']);

        // Most recent first
        $this->assertSame('m240101_000002_add_test_column', $result['history'][0]['version']);
        $this->assertSame('m240101_000001_create_test_table', $result['history'][1]['version']);
        $this->assertArrayHasKey('applied_at', $result['history'][0]);
    }

    public function testGetHistoryExcludesBase(): void
    {
        $this->insertAppliedMigration('m000000_000000_base', 0);
        $this->insertAppliedMigration('m240101_000001_create_test_table', time());

        $result = $this->tool->execute(['include' => ['history']]);

        $versions = array_column($result['history'], 'version');
        $this->assertNotContains('m000000_000000_base', $versions);
        $this->assertContains('m240101_000001_create_test_table', $versions);
    }

    public function testGetPending(): void
    {
        // No applied migrations — both fixtures should be pending
        $result = $this->tool->execute(['include' => ['pending']]);

        $this->assertArrayHasKey('pending', $result);
        $versions = array_column($result['pending'], 'version');
        $this->assertContains('m240101_000001_create_test_table', $versions);
        $this->assertContains('m240101_000002_add_test_column', $versions);
    }

    public function testGetPendingExcludesApplied(): void
    {
        $this->insertAppliedMigration('m240101_000001_create_test_table', time());

        $result = $this->tool->execute(['include' => ['pending']]);

        $versions = array_column($result['pending'], 'version');
        $this->assertNotContains('m240101_000001_create_test_table', $versions);
        $this->assertContains('m240101_000002_add_test_column', $versions);
    }

    public function testGetPendingSorted(): void
    {
        $result = $this->tool->execute(['include' => ['pending']]);
        $versions = array_column($result['pending'], 'version');

        // Filter to only our known fixture migrations
        $fixtureVersions = array_filter($versions, function ($v) {
            return strpos($v, 'm240101_') === 0;
        });
        $fixtureVersions = array_values($fixtureVersions);

        $this->assertSame('m240101_000001_create_test_table', $fixtureVersions[0]);
        $this->assertSame('m240101_000002_add_test_column', $fixtureVersions[1]);
    }

    public function testViewMigrationApplied(): void
    {
        $applyTime = time() - 3600;
        $this->insertAppliedMigration('m240101_000001_create_test_table', $applyTime);

        $result = $this->tool->execute(['migration' => 'm240101_000001_create_test_table']);

        $this->assertSame('m240101_000001_create_test_table', $result['version']);
        $this->assertTrue($result['applied']);
        $this->assertArrayHasKey('applied_at', $result);
        $this->assertArrayHasKey('file', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertStringContainsString('createTable', $result['source']);
        $this->assertStringContainsString('test_table', $result['source']);
    }

    public function testViewMigrationNotApplied(): void
    {
        $result = $this->tool->execute(['migration' => 'm240101_000002_add_test_column']);

        $this->assertSame('m240101_000002_add_test_column', $result['version']);
        $this->assertFalse($result['applied']);
        $this->assertArrayNotHasKey('applied_at', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertStringContainsString('addColumn', $result['source']);
    }

    public function testViewMigrationNotFound(): void
    {
        $result = $this->tool->execute(['migration' => 'm999999_999999_nonexistent']);

        $this->assertSame('m999999_999999_nonexistent', $result['version']);
        $this->assertFalse($result['applied']);
        $this->assertNull($result['file']);
        $this->assertArrayHasKey('note', $result);
    }

    public function testIncludeAll(): void
    {
        $this->insertAppliedMigration('m240101_000001_create_test_table', time());

        $result = $this->tool->execute(['include' => ['all']]);

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('history', $result);
        $this->assertArrayHasKey('pending', $result);
    }

    public function testDefaultInclude(): void
    {
        $result = $this->tool->execute([]);

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('history', $result);
        $this->assertArrayNotHasKey('pending', $result);
    }

    public function testLimit(): void
    {
        $this->insertAppliedMigration('m240101_000001_create_test_table', time() - 7200);
        $this->insertAppliedMigration('m240101_000002_add_test_column', time() - 3600);

        $result = $this->tool->execute(['include' => ['history'], 'limit' => 1]);

        $this->assertCount(1, $result['history']);
    }

    public function testNoMigrationTable(): void
    {
        // Drop the migration table
        $this->dropMigrationTable();

        // Should still work without errors — just show 0 applied
        $result = $this->tool->execute(['include' => ['status', 'history', 'pending']]);

        $this->assertSame(0, $result['status']['applied']);
        $this->assertEmpty($result['history']);
        $this->assertNotEmpty($result['pending']);
    }

    public function testPendingIncludesFilePath(): void
    {
        $result = $this->tool->execute(['include' => ['pending']]);

        foreach ($result['pending'] as $entry) {
            $this->assertArrayHasKey('version', $entry);
            $this->assertArrayHasKey('file', $entry);
        }
    }

    /**
     * Create the migration table in SQLite
     */
    private function createMigrationTable(): void
    {
        $db = \Yii::$app->db;
        try {
            $db->createCommand()->createTable('{{%migration}}', [
                'version' => 'string(180) NOT NULL',
                'apply_time' => 'integer',
            ])->execute();
            $db->createCommand(
                'CREATE UNIQUE INDEX idx_migration_version ON {{%migration}} (version)'
            )->execute();
        } catch (\Exception $e) {
            // Table may already exist
        }
    }

    /**
     * Drop the migration table
     */
    private function dropMigrationTable(): void
    {
        $db = \Yii::$app->db;
        try {
            $db->createCommand()->dropTable('{{%migration}}')->execute();
        } catch (\Exception $e) {
            // Table may not exist
        }
    }

    /**
     * Insert an applied migration record
     *
     * @param string $version Migration version name
     * @param int $applyTime Unix timestamp
     */
    private function insertAppliedMigration(string $version, int $applyTime): void
    {
        \Yii::$app->db->createCommand()->insert('{{%migration}}', [
            'version' => $version,
            'apply_time' => $applyTime,
        ])->execute();
    }
}
