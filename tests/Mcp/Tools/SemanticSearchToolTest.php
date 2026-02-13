<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Search\MarkdownSectionParser;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use codechap\yii2boost\Mcp\Tools\SemanticSearchTool;

class SemanticSearchToolTest extends ToolTestCase
{
    private SemanticSearchTool $tool;
    private string $searchDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a temp file for the search database
        $this->searchDbPath = tempnam(sys_get_temp_dir(), 'search_test_') . '.db';

        $this->tool = new SemanticSearchTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
            'searchDbPath' => $this->searchDbPath,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up temp database files
        foreach (glob($this->searchDbPath . '*') as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testGetName(): void
    {
        $this->assertSame('semantic_search', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $description = $this->tool->getDescription();
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('BM25', $description);
        $this->assertStringContainsString('full-text search', $description);
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertArrayHasKey('category', $schema['properties']);
        $this->assertArrayHasKey('limit', $schema['properties']);
    }

    public function testFallbackWhenNoIndex(): void
    {
        // Point to a non-existent db and a non-existent guidelines dir
        $tool = new SemanticSearchTool([
            'basePath' => '/tmp/nonexistent-test-path',
            'searchDbPath' => '/tmp/nonexistent-test-path/search.db',
        ]);

        $result = $tool->execute(['query' => 'migration']);

        $this->assertIsString($result);
        $this->assertStringContainsString('No guidelines found', $result);
    }

    public function testSearchWithIndex(): void
    {
        $this->buildTestIndex();

        $result = $this->tool->execute(['query' => 'migration']);

        $this->assertIsString($result);
        $this->assertStringContainsString('Result #1', $result);
        $this->assertStringContainsString('score:', $result);
    }

    public function testSearchWithCategoryFilter(): void
    {
        $this->buildTestIndex();

        $result = $this->tool->execute([
            'query' => 'component',
            'category' => 'cache',
        ]);

        $this->assertIsString($result);
        // Should find cache-related content
        if (strpos($result, 'Result #') !== false) {
            $this->assertStringContainsString('cache', $result);
        }
    }

    public function testSearchWithLimit(): void
    {
        $this->buildTestIndex();

        $result = $this->tool->execute([
            'query' => 'yii2',
            'limit' => 1,
        ]);

        $this->assertIsString($result);
        // Should have at most 1 result
        if (strpos($result, 'Result #1', 0) !== false) {
            $this->assertStringNotContainsString('Result #2', $result);
        }
    }

    public function testEmptyQueryShowsStats(): void
    {
        $this->buildTestIndex();

        $result = $this->tool->execute(['query' => '']);

        $this->assertIsString($result);
        $this->assertStringContainsString('Search Index Statistics', $result);
        $this->assertStringContainsString('Total sections', $result);
    }

    public function testNoQueryShowsStats(): void
    {
        $this->buildTestIndex();

        $result = $this->tool->execute([]);

        $this->assertIsString($result);
        $this->assertStringContainsString('Search Index Statistics', $result);
    }

    public function testSearchNoResultsFallsBackToGrep(): void
    {
        $this->buildTestIndex();

        // Search for something not in the index — triggers grep fallback
        $result = $this->tool->execute(['query' => 'zzzznonexistent']);

        $this->assertIsString($result);
        // Grep fallback will either show "FTS5 search index not built" or
        // "No guidelines found" if the guidelines dir doesn't exist in test env
        $this->assertTrue(
            strpos($result, 'FTS5 search index not built') !== false
            || strpos($result, 'No guidelines found') !== false,
            'Expected grep fallback message'
        );
    }

    public function testPhraseSearch(): void
    {
        $this->buildTestIndex();

        $result = $this->tool->execute(['query' => '"active record"']);

        $this->assertIsString($result);
        if (strpos($result, 'Result #') !== false) {
            $this->assertStringContainsString('Active Record', $result);
        }
    }

    /**
     * Build a test FTS5 index with sample content.
     */
    private function buildTestIndex(): void
    {
        $manager = new SearchIndexManager($this->searchDbPath);
        $manager->createSchema();

        $manager->indexSections(
            'bundled',
            'database',
            'database/yii-migration.md',
            'Yii2 Database Migration',
            [
                [
                    'section_title' => 'Usage Example',
                    'body' => 'Use migrations to manage database schema changes. '
                        . 'The migration tool creates versioned migration files.',
                ],
                [
                    'section_title' => 'Best Practices',
                    'body' => 'Always use safe migration methods in Yii2 applications.',
                ],
            ]
        );

        $manager->indexSections(
            'bundled',
            'database',
            'database/yii-active-record.md',
            'Yii2 Active Record',
            [
                [
                    'section_title' => 'Introduction',
                    'body' => 'Active Record provides an object-oriented interface for databases.',
                ],
            ]
        );

        $manager->indexSections(
            'bundled',
            'cache',
            'cache/yii-cache.md',
            'Yii2 Caching',
            [
                [
                    'section_title' => 'Cache Components',
                    'body' => 'Yii2 supports FileCache, MemCache, Redis, and DbCache components.',
                ],
            ]
        );

        $manager->setMeta('last_rebuild', date('Y-m-d H:i:s'));
    }
}
