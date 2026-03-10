<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;
use yii\helpers\FileHelper;

/**
 * FTS5-powered semantic search over Yii2 guidelines and documentation.
 *
 * Replaces the old grep-based SearchGuidelinesTool with BM25-ranked,
 * section-level search using SQLite FTS5. Falls back to grep search
 * if the FTS5 index has not been built yet.
 */
final class SemanticSearchTool extends BaseTool
{
    /**
     * @var string|null Override path for search index (used in tests)
     */
    public $searchDbPath;

    public function getName(): string
    {
        return 'semantic_search';
    }

    public function getDescription(): string
    {
        return 'Searches Yii2 guidelines and documentation using full-text search with BM25 ranking. '
            . 'Returns relevant sections (not full files) ranked by relevance. '
            . 'Supports phrases ("active record"), boolean (migration AND database), '
            . 'and prefix queries (migrat*). Use this when the user asks "How do I..." '
            . 'questions about Yii2.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query. Supports FTS5 syntax: phrases ("active record"), '
                        . 'boolean operators (migration AND database), prefix matching (migrat*). '
                        . 'Leave empty to show index statistics.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Optional category filter. Use "all" to search everything.',
                    'default' => 'all',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results (1-10)',
                    'default' => 5,
                    'minimum' => 1,
                    'maximum' => 10,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = trim($arguments['query'] ?? '');
        $category = $arguments['category'] ?? 'all';
        $limit = min(10, max(1, (int) ($arguments['limit'] ?? 5)));

        $dbPath = $this->getSearchDbPath();

        // If no index exists, fall back to grep
        if (!file_exists($dbPath)) {
            return $this->grepFallback($query, $category);
        }

        try {
            $manager = new SearchIndexManager($dbPath);
        } catch (\Exception $e) {
            return $this->grepFallback($query, $category);
        }

        // Empty query — show stats
        if ($query === '') {
            return $this->showStats($manager);
        }

        // Search
        $results = $manager->search($query, $category, $limit);

        if (empty($results)) {
            // Try grep fallback if FTS returned nothing
            return $this->grepFallback($query, $category);
        }

        return $this->formatResults($results, $query);
    }

    /**
     * Format FTS5 search results for output.
     *
     * @param array $results Raw search results
     * @param string $query Original query
     * @return string Formatted output
     */
    private function formatResults(array $results, string $query): string
    {
        $output = "Found " . count($results) . " results for \"" . $query . "\":\n\n";

        foreach ($results as $i => $result) {
            $rank = $i + 1;
            $score = round(abs((float) $result['rank']), 4);
            $source = $result['source'] === 'yii2_guide' ? 'Yii2 Guide' : 'Bundled';

            $output .= "--- Result #{$rank} (score: {$score}) ---\n";
            $output .= "Source: {$source} | Category: {$result['category']}\n";
            $output .= "File: {$result['file_path']}\n";
            $output .= "Section: {$result['file_title']} > {$result['section_title']}\n\n";

            // Snippet: first 500 chars of body
            $snippet = $result['body'];
            if (strlen($snippet) > 500) {
                $snippet = substr($snippet, 0, 500) . '...';
            }
            $output .= $snippet . "\n\n";
        }

        return $output;
    }

    /**
     * Show index statistics when no query provided.
     *
     * @param SearchIndexManager $manager
     * @return string Index stats output
     */
    private function showStats(SearchIndexManager $manager): string
    {
        $stats = $manager->getStats();
        $categories = $manager->getCategories();

        $output = "Search Index Statistics:\n\n";
        $output .= "  Total sections: {$stats['total_sections']}\n";
        $output .= "  Last rebuild: {$stats['last_rebuild']}\n\n";

        if (!empty($stats['sources'])) {
            $output .= "Sources:\n";
            foreach ($stats['sources'] as $source => $count) {
                $label = $source === 'yii2_guide' ? 'Yii2 Guide' : 'Bundled Guidelines';
                $output .= "  - {$label}: {$count} sections\n";
            }
            $output .= "\n";
        }

        if (!empty($categories)) {
            $output .= "Categories:\n";
            foreach ($categories as $cat) {
                $output .= "  - {$cat}\n";
            }
            $output .= "\n";
        }

        $output .= "Use semantic_search with a query to search (e.g., query: 'migration')";

        return $output;
    }

    /**
     * Get the path to the search database.
     *
     * @return string
     */
    private function getSearchDbPath(): string
    {
        if ($this->searchDbPath !== null) {
            return $this->searchDbPath;
        }

        return \Yii::getAlias('@runtime') . '/boost/search.db';
    }

    /**
     * Grep-based fallback search (from the old SearchGuidelinesTool logic).
     *
     * Used when the FTS5 index hasn't been built yet.
     *
     * @param string $query Search query
     * @param string $category Category filter
     * @return string Formatted output
     */
    private function grepFallback(string $query, string $category): string
    {
        $root = $this->projectRoot ?: $this->basePath;
        $guidelinesPath = $root . '/.ai/guidelines';

        if (!is_dir($guidelinesPath)) {
            return "No guidelines found at {$guidelinesPath}. Run 'php yii boost/install' first.";
        }

        $files = FileHelper::findFiles($guidelinesPath, [
            'only' => ['*.md'],
            'recursive' => true,
        ]);

        $warning = "[Note: FTS5 search index not built. Using basic text search. "
            . "Run 'php yii boost/update' to build the full-text search index for better results.]\n\n";

        // Empty query — list topics
        if ($query === '') {
            return $warning . $this->listTopics($files, $guidelinesPath, $category);
        }

        $query = strtolower($query);
        $results = [];

        foreach ($files as $file) {
            $relativePath = str_replace($guidelinesPath . '/', '', $file);
            $fileCategory = dirname($relativePath);

            if ($category !== 'all' && $fileCategory !== $category && strpos($fileCategory, $category) === false) {
                continue;
            }

            $content = file_get_contents($file);
            $filename = basename($file);

            $score = 0;
            if (strpos(strtolower($filename), $query) !== false) {
                $score += 10;
            }
            $matches = substr_count(strtolower($content), $query);
            $score += min($matches, 5);

            if ($score > 0) {
                $results[] = [
                    'path' => $relativePath,
                    'score' => $score,
                    'content' => $content,
                ];
            }
        }

        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $topResults = array_slice($results, 0, 3);

        if (empty($topResults)) {
            return $warning . "No guidelines found matching '{$query}'. Use empty query to list available topics.";
        }

        $output = $warning . "Found " . count($topResults) . " relevant guidelines:\n\n";
        foreach ($topResults as $result) {
            $output .= "--- File: {$result['path']} ---\n";
            $output .= $result['content'] . "\n\n";
        }

        return $output;
    }

    /**
     * List available topics from guideline files.
     *
     * @param array $files List of guideline file paths
     * @param string $guidelinesPath Base guidelines path
     * @param string $category Category filter
     * @return string Formatted topic list
     */
    private function listTopics(array $files, string $guidelinesPath, string $category): string
    {
        $topics = [];

        foreach ($files as $file) {
            $relativePath = str_replace($guidelinesPath . '/', '', $file);
            $fileCategory = dirname($relativePath);
            $filename = basename($file, '.md');

            if ($category !== 'all' && $fileCategory !== $category) {
                continue;
            }

            $content = file_get_contents($file);
            preg_match('/^#\s+(.+)$/m', $content, $matches);
            $title = $matches[1] ?? $filename;
            $sizeKb = round(filesize($file) / 1024, 1);

            $topics[$fileCategory][] = [
                'name' => $filename,
                'title' => $title,
                'size' => $sizeKb,
            ];
        }

        if (empty($topics)) {
            return "No guidelines found" . ($category !== 'all' ? " in category '{$category}'" : "") . ".";
        }

        $output = "Available Yii2 Guidelines:\n\n";
        foreach ($topics as $cat => $items) {
            $output .= "## {$cat}\n";
            foreach ($items as $item) {
                $output .= "  - {$item['title']} ({$item['size']}KB)\n";
            }
            $output .= "\n";
        }

        $output .= "Use semantic_search with a query to search (e.g., query: 'migration')";

        return $output;
    }
}
