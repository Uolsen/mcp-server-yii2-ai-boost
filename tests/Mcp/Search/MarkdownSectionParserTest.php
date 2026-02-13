<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Search;

use PHPUnit\Framework\TestCase;
use codechap\yii2boost\Mcp\Search\MarkdownSectionParser;

class MarkdownSectionParserTest extends TestCase
{
    private MarkdownSectionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MarkdownSectionParser();
    }

    public function testParsesH1AsFileTitle(): void
    {
        $md = "# My Document Title\n\nSome intro text.\n";
        $result = $this->parser->parse($md, 'test.md');

        $this->assertSame('My Document Title', $result['file_title']);
    }

    public function testFallsBackToFilenameWithoutH1(): void
    {
        $md = "## Section One\n\nBody text.\n";
        $result = $this->parser->parse($md, '/path/to/guide.md');

        $this->assertSame('guide', $result['file_title']);
    }

    public function testSplitsOnH2Headings(): void
    {
        $md = <<<'MD'
# Guide Title

## First Section

First body content.

## Second Section

Second body content.

## Third Section

Third body content.
MD;

        $result = $this->parser->parse($md, 'guide.md');

        $this->assertSame('Guide Title', $result['file_title']);
        $this->assertCount(3, $result['sections']);

        $this->assertSame('First Section', $result['sections'][0]['section_title']);
        $this->assertSame('First body content.', $result['sections'][0]['body']);

        $this->assertSame('Second Section', $result['sections'][1]['section_title']);
        $this->assertSame('Second body content.', $result['sections'][1]['body']);

        $this->assertSame('Third Section', $result['sections'][2]['section_title']);
        $this->assertSame('Third body content.', $result['sections'][2]['body']);
    }

    public function testContentBeforeFirstH2BecomesIntroduction(): void
    {
        $md = <<<'MD'
# Title

This is intro text before any section.

## First Section

Section body.
MD;

        $result = $this->parser->parse($md, 'test.md');

        $this->assertCount(2, $result['sections']);
        $this->assertSame('Introduction', $result['sections'][0]['section_title']);
        $this->assertSame('This is intro text before any section.', $result['sections'][0]['body']);
        $this->assertSame('First Section', $result['sections'][1]['section_title']);
    }

    public function testFileWithNoH2ReturnsSingleContentSection(): void
    {
        $md = <<<'MD'
# Simple File

Just a single block of content with no subsections.

Some more text here.
MD;

        $result = $this->parser->parse($md, 'simple.md');

        $this->assertSame('Simple File', $result['file_title']);
        $this->assertCount(1, $result['sections']);
        $this->assertSame('Content', $result['sections'][0]['section_title']);
        $this->assertStringContainsString('single block of content', $result['sections'][0]['body']);
    }

    public function testEmptyMarkdownReturnsNoSections(): void
    {
        $result = $this->parser->parse('', 'empty.md');

        $this->assertSame('empty', $result['file_title']);
        $this->assertCount(0, $result['sections']);
    }

    public function testPreservesCodeBlocks(): void
    {
        $md = <<<'MD'
# Guide

## Code Example

```php
class Foo {
    public function bar(): void {}
}
```
MD;

        $result = $this->parser->parse($md, 'guide.md');

        $this->assertCount(1, $result['sections']);
        $this->assertStringContainsString('```php', $result['sections'][0]['body']);
        $this->assertStringContainsString('class Foo', $result['sections'][0]['body']);
    }

    public function testTrimsBlankLinesFromSectionBodies(): void
    {
        $md = "# Title\n\n## Section\n\n\nContent here.\n\n\n";
        $result = $this->parser->parse($md, 'test.md');

        $this->assertCount(1, $result['sections']);
        $this->assertSame('Content here.', $result['sections'][0]['body']);
    }

    public function testH3HeadingsDoNotSplitSections(): void
    {
        $md = <<<'MD'
# Guide

## Main Section

### Subsection A

Text A.

### Subsection B

Text B.
MD;

        $result = $this->parser->parse($md, 'guide.md');

        $this->assertCount(1, $result['sections']);
        $this->assertSame('Main Section', $result['sections'][0]['section_title']);
        $this->assertStringContainsString('### Subsection A', $result['sections'][0]['body']);
        $this->assertStringContainsString('### Subsection B', $result['sections'][0]['body']);
    }

    public function testRealWorldGuidelineFormat(): void
    {
        $md = <<<'MD'
# Yii2 Database Migration

```php
namespace yii\db;

class Migration extends Component
{
    public $db = 'db';
}
```

## Usage Example
```php
class m210101_000000_create_user extends Migration
{
    public function up()
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey(),
        ]);
    }
}
```

## Best Practices

- Always use safe methods
- Use transactions for complex changes
MD;

        $result = $this->parser->parse($md, 'yii-migration.md');

        $this->assertSame('Yii2 Database Migration', $result['file_title']);
        $this->assertCount(3, $result['sections']);
        $this->assertSame('Introduction', $result['sections'][0]['section_title']);
        $this->assertSame('Usage Example', $result['sections'][1]['section_title']);
        $this->assertSame('Best Practices', $result['sections'][2]['section_title']);
    }
}
