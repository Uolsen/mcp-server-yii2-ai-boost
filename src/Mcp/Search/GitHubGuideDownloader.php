<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Search;

/**
 * Downloads the Yii2 definitive guide from GitHub and caches it locally.
 *
 * Uses the GitHub Contents API to list files, then fetches raw content
 * from raw.githubusercontent.com (no rate limit). Cached files persist
 * across updates for offline fallback.
 */
class GitHubGuideDownloader
{
    /**
     * GitHub API URL for listing guide files
     */
    private const API_URL = 'https://api.github.com/repos/yiisoft/yii2/contents/docs/guide';

    /**
     * Raw content base URL (CDN, no rate limit)
     */
    private const RAW_URL = 'https://raw.githubusercontent.com/yiisoft/yii2/master/docs/guide/';

    /**
     * @var string Path to cache directory
     */
    private $cachePath;

    /**
     * @var int HTTP timeout in seconds
     */
    private $timeout;

    /**
     * @var callable|null Custom HTTP fetcher for testing
     */
    private $httpFetcher;

    /**
     * @param string $cachePath Directory to cache downloaded guide files
     * @param int $timeout HTTP request timeout in seconds
     */
    public function __construct(string $cachePath, int $timeout = 5)
    {
        $this->cachePath = $cachePath;
        $this->timeout = $timeout;
    }

    /**
     * Set a custom HTTP fetcher (for testing).
     *
     * @param callable $fetcher Function(string $url): string|false
     */
    public function setHttpFetcher(callable $fetcher): void
    {
        $this->httpFetcher = $fetcher;
    }

    /**
     * Download the Yii2 guide from GitHub.
     *
     * @return array{downloaded: int, skipped: int, failed: int, errors: array<string>}
     */
    public function download(): array
    {
        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        $result = [
            'downloaded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Get file list from GitHub API
        $fileList = $this->fetchFileList();
        if ($fileList === null) {
            $result['errors'][] = 'Failed to fetch file list from GitHub API';
            return $result;
        }

        // Download each markdown file
        foreach ($fileList as $file) {
            if (!isset($file['name']) || !str_ends_with($file['name'], '.md')) {
                continue;
            }

            $filename = $file['name'];
            $localPath = $this->cachePath . '/' . $filename;

            // Check if we already have this file (by SHA if available)
            if (isset($file['sha']) && file_exists($localPath)) {
                $existingSha = $this->getFileSha($localPath);
                if ($existingSha === $file['sha']) {
                    $result['skipped']++;
                    continue;
                }
            }

            $content = $this->fetchFile(self::RAW_URL . $filename);
            if ($content === false) {
                $result['failed']++;
                $result['errors'][] = "Failed to download: {$filename}";
                continue;
            }

            file_put_contents($localPath, $content);
            $result['downloaded']++;
        }

        return $result;
    }

    /**
     * Get list of cached guide files.
     *
     * @return array<string> List of file paths
     */
    public function getCachedFiles(): array
    {
        if (!is_dir($this->cachePath)) {
            return [];
        }

        $files = glob($this->cachePath . '/*.md');
        return $files !== false ? $files : [];
    }

    /**
     * Check if cached guide files exist.
     *
     * @return bool
     */
    public function hasCachedFiles(): bool
    {
        return !empty($this->getCachedFiles());
    }

    /**
     * Map a guide filename to a category.
     *
     * Guide filenames use prefixes: db-*.md -> guide_db, security-*.md -> guide_security, etc.
     *
     * @param string $filename Guide filename (e.g., 'db-migrations.md')
     * @return string Category name
     */
    public static function mapCategory(string $filename): string
    {
        $basename = basename($filename, '.md');

        // Map prefixes to categories
        $prefixMap = [
            'db-' => 'guide_db',
            'security-' => 'guide_security',
            'start-' => 'guide_start',
            'structure-' => 'guide_structure',
            'runtime-' => 'guide_runtime',
            'input-' => 'guide_input',
            'output-' => 'guide_output',
            'caching-' => 'guide_caching',
            'rest-' => 'guide_rest',
            'test-' => 'guide_testing',
            'tutorial-' => 'guide_tutorial',
            'widget-' => 'guide_widget',
            'helper-' => 'guide_helper',
        ];

        foreach ($prefixMap as $prefix => $category) {
            if (strpos($basename, $prefix) === 0) {
                return $category;
            }
        }

        // Specific files without common prefix
        $specificMap = [
            'concept-aliases' => 'guide_concept',
            'concept-autoloading' => 'guide_concept',
            'concept-behaviors' => 'guide_concept',
            'concept-components' => 'guide_concept',
            'concept-configurations' => 'guide_concept',
            'concept-di-container' => 'guide_concept',
            'concept-events' => 'guide_concept',
            'concept-properties' => 'guide_concept',
            'concept-service-locator' => 'guide_concept',
        ];

        if (isset($specificMap[$basename])) {
            return $specificMap[$basename];
        }

        // Default: extract prefix before first hyphen
        $parts = explode('-', $basename, 2);
        if (count($parts) > 1) {
            return 'guide_' . $parts[0];
        }

        return 'guide_general';
    }

    /**
     * Get the cache directory path.
     *
     * @return string
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Fetch the file list from GitHub Contents API.
     *
     * @return array|null Parsed JSON array or null on failure
     */
    private function fetchFileList(): ?array
    {
        $content = $this->fetchFile(self::API_URL);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Fetch content from a URL.
     *
     * @param string $url URL to fetch
     * @return string|false Content or false on failure
     */
    private function fetchFile(string $url)
    {
        if ($this->httpFetcher !== null) {
            return ($this->httpFetcher)($url);
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'header' => "User-Agent: yii2-ai-boost\r\n",
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        return $content !== false ? $content : false;
    }

    /**
     * Compute the git-compatible SHA1 of a file's content.
     *
     * Git SHA1 = sha1("blob {size}\0{content}")
     *
     * @param string $filePath Local file path
     * @return string SHA1 hex string
     */
    private function getFileSha(string $filePath): string
    {
        $content = file_get_contents($filePath);
        return sha1("blob " . strlen($content) . "\0" . $content);
    }
}
