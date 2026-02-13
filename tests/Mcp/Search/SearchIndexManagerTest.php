<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Search;

use PHPUnit\Framework\TestCase;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;

class SearchIndexManagerTest extends TestCase
{
    private SearchIndexManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SearchIndexManager(':memory:');
        $this->manager->createSchema();
    }

    public function testCreateSchemaIsIdempotent(): void
    {
        // Call twice — should not throw
        $this->manager->createSchema();
        $this->manager->createSchema();

        $stats = $this->manager->getStats();
        $this->assertSame(0, $stats['total_sections']);
    }

    public function testIndexSections(): void
    {
        $count = $this->manager->indexSections(
            'bundled',
            'database',
            'database/yii-migration.md',
            'Yii2 Database Migration',
            [
                ['section_title' => 'Usage Example', 'body' => 'Use migrations to manage database schema.'],
                ['section_title' => 'Best Practices', 'body' => 'Always use safe migration methods.'],
            ]
        );

        $this->assertSame(2, $count);

        $stats = $this->manager->getStats();
        $this->assertSame(2, $stats['total_sections']);
        $this->assertSame(['bundled' => 2], $stats['sources']);
        $this->assertSame(['database' => 2], $stats['categories']);
    }

    public function testIndexSkipsEmptyBodies(): void
    {
        $count = $this->manager->indexSections(
            'bundled',
            'core',
            'core/test.md',
            'Test',
            [
                ['section_title' => 'Empty', 'body' => ''],
                ['section_title' => 'Has Content', 'body' => 'Some content here.'],
            ]
        );

        $this->assertSame(1, $count);
    }

    public function testSearchReturnsBm25RankedResults(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('migration');

        $this->assertNotEmpty($results);
        // Results should include the migration section
        $titles = array_column($results, 'section_title');
        $this->assertTrue(
            in_array('Usage Example', $titles) || in_array('Introduction', $titles),
            'Expected migration-related sections in results'
        );
    }

    public function testSearchWithCategoryFilter(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('migration', 'database');
        $this->assertNotEmpty($results);

        // All results should be in database category
        foreach ($results as $result) {
            $this->assertSame('database', $result['category']);
        }
    }

    public function testSearchNonExistentCategoryReturnsEmpty(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('migration', 'nonexistent');
        $this->assertEmpty($results);
    }

    public function testSearchRespectsLimit(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('yii2', 'all', 2);
        $this->assertLessThanOrEqual(2, count($results));
    }

    public function testSearchEmptyQueryReturnsEmpty(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('');
        $this->assertEmpty($results);

        $results = $this->manager->search('   ');
        $this->assertEmpty($results);
    }

    public function testSearchPhraseQuery(): void
    {
        $this->indexTestContent();

        // FTS5 phrase query
        $results = $this->manager->search('"active record"');
        $this->assertNotEmpty($results);
    }

    public function testSearchPrefixQuery(): void
    {
        $this->indexTestContent();

        // FTS5 prefix query
        $results = $this->manager->search('migrat*');
        $this->assertNotEmpty($results);
    }

    public function testSearchPorterStemming(): void
    {
        $this->indexTestContent();

        // "migrating" should match "migration" via porter stemmer
        $results = $this->manager->search('migrating');
        $this->assertNotEmpty($results);
    }

    public function testClearIndex(): void
    {
        $this->indexTestContent();
        $stats = $this->manager->getStats();
        $this->assertGreaterThan(0, $stats['total_sections']);

        $this->manager->clearIndex();

        $stats = $this->manager->getStats();
        $this->assertSame(0, $stats['total_sections']);
    }

    public function testGetCategories(): void
    {
        $this->indexTestContent();

        $categories = $this->manager->getCategories();
        $this->assertContains('database', $categories);
        $this->assertContains('cache', $categories);
    }

    public function testMetadata(): void
    {
        $this->manager->setMeta('test_key', 'test_value');
        $this->assertSame('test_value', $this->manager->getMeta('test_key'));

        // Update
        $this->manager->setMeta('test_key', 'updated');
        $this->assertSame('updated', $this->manager->getMeta('test_key'));

        // Non-existent key
        $this->assertNull($this->manager->getMeta('nonexistent'));
    }

    public function testGetStatsReturnsExpectedKeys(): void
    {
        $stats = $this->manager->getStats();

        $this->assertArrayHasKey('total_sections', $stats);
        $this->assertArrayHasKey('sources', $stats);
        $this->assertArrayHasKey('categories', $stats);
        $this->assertArrayHasKey('last_rebuild', $stats);
        $this->assertArrayHasKey('db_path', $stats);
        $this->assertSame('never', $stats['last_rebuild']);
    }

    public function testIsFts5Available(): void
    {
        $this->assertTrue(SearchIndexManager::isFts5Available());
    }

    public function testMultipleSourcesIndexed(): void
    {
        $this->manager->indexSections(
            'bundled',
            'database',
            'database/migration.md',
            'Migration',
            [['section_title' => 'Intro', 'body' => 'Bundled migration content.']]
        );

        $this->manager->indexSections(
            'yii2_guide',
            'database',
            'db-migrations.md',
            'Database Migrations',
            [['section_title' => 'Overview', 'body' => 'Guide migration content.']]
        );

        $stats = $this->manager->getStats();
        $this->assertSame(2, $stats['total_sections']);
        $this->assertSame(['bundled' => 1, 'yii2_guide' => 1], $stats['sources']);
    }

    /**
     * Index test content for search tests.
     */
    private function indexTestContent(): void
    {
        $this->manager->indexSections(
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
                    'body' => 'Always use safe migration methods. '
                        . 'Never modify an already-applied migration.',
                ],
            ]
        );

        $this->manager->indexSections(
            'bundled',
            'database',
            'database/yii-active-record.md',
            'Yii2 Active Record',
            [
                [
                    'section_title' => 'Introduction',
                    'body' => 'Active Record provides an object-oriented interface '
                        . 'for accessing and manipulating data stored in databases.',
                ],
                [
                    'section_title' => 'Relations',
                    'body' => 'Use hasMany and hasOne to define relations between models.',
                ],
            ]
        );

        $this->manager->indexSections(
            'bundled',
            'cache',
            'cache/yii-cache.md',
            'Yii2 Caching',
            [
                [
                    'section_title' => 'Cache Components',
                    'body' => 'Yii2 supports various cache backends: FileCache, '
                        . 'MemCache, Redis, DbCache, and ApcCache.',
                ],
            ]
        );
    }
}
