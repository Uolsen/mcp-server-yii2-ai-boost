<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Search;

use PHPUnit\Framework\TestCase;
use codechap\yii2boost\Mcp\Search\GitHubGuideDownloader;

class GitHubGuideDownloaderTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir() . '/yii2-guide-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up cache directory
        if (is_dir($this->cachePath)) {
            $files = glob($this->cachePath . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->cachePath);
        }

        parent::tearDown();
    }

    public function testDownloadWithMockedHttp(): void
    {
        $apiResponse = file_get_contents(__DIR__ . '/../../fixtures/github-api-response.json');
        $downloader = new GitHubGuideDownloader($this->cachePath);

        $downloader->setHttpFetcher(function (string $url) use ($apiResponse) {
            // API listing request
            if (strpos($url, 'api.github.com') !== false) {
                return $apiResponse;
            }

            // Raw content requests
            $filename = basename($url);
            return "# " . str_replace('.md', '', $filename) . "\n\nMocked content for {$filename}.\n";
        });

        $result = $downloader->download();

        // 5 .md files in the fixture (README, db-migrations, db-active-record, security-auth, start-installation)
        // "images" is a dir and should be skipped
        $this->assertSame(5, $result['downloaded']);
        $this->assertSame(0, $result['failed']);
        $this->assertEmpty($result['errors']);

        // Files should exist on disk
        $this->assertFileExists($this->cachePath . '/db-migrations.md');
        $this->assertFileExists($this->cachePath . '/db-active-record.md');
    }

    public function testDownloadSkipsExistingFilesWithMatchingSha(): void
    {
        // Pre-populate cache
        mkdir($this->cachePath, 0755, true);

        // Create a file whose SHA matches the fixture
        $content = "# db-migrations\n\nMocked content for db-migrations.md.\n";
        file_put_contents($this->cachePath . '/db-migrations.md', $content);
        $sha = sha1("blob " . strlen($content) . "\0" . $content);

        // Build API response with matching SHA
        $apiResponse = json_encode([
            [
                'name' => 'db-migrations.md',
                'sha' => $sha,
                'type' => 'file',
            ],
        ]);

        $downloader = new GitHubGuideDownloader($this->cachePath);
        $downloader->setHttpFetcher(function (string $url) use ($apiResponse) {
            if (strpos($url, 'api.github.com') !== false) {
                return $apiResponse;
            }
            return "Updated content";
        });

        $result = $downloader->download();

        $this->assertSame(0, $result['downloaded']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testDownloadHandlesApiFailure(): void
    {
        $downloader = new GitHubGuideDownloader($this->cachePath);
        $downloader->setHttpFetcher(function (string $url) {
            return false;
        });

        $result = $downloader->download();

        $this->assertSame(0, $result['downloaded']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Failed to fetch file list', $result['errors'][0]);
    }

    public function testDownloadHandlesFileDownloadFailure(): void
    {
        $apiResponse = json_encode([
            ['name' => 'test.md', 'sha' => 'abc', 'type' => 'file'],
        ]);

        $downloader = new GitHubGuideDownloader($this->cachePath);
        $downloader->setHttpFetcher(function (string $url) use ($apiResponse) {
            if (strpos($url, 'api.github.com') !== false) {
                return $apiResponse;
            }
            return false; // File download fails
        });

        $result = $downloader->download();

        $this->assertSame(0, $result['downloaded']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('test.md', $result['errors'][0]);
    }

    public function testGetCachedFiles(): void
    {
        mkdir($this->cachePath, 0755, true);
        file_put_contents($this->cachePath . '/test1.md', 'content');
        file_put_contents($this->cachePath . '/test2.md', 'content');

        $downloader = new GitHubGuideDownloader($this->cachePath);
        $files = $downloader->getCachedFiles();

        $this->assertCount(2, $files);
    }

    public function testGetCachedFilesEmptyDir(): void
    {
        $downloader = new GitHubGuideDownloader($this->cachePath);
        $files = $downloader->getCachedFiles();

        $this->assertEmpty($files);
    }

    public function testHasCachedFiles(): void
    {
        $downloader = new GitHubGuideDownloader($this->cachePath);
        $this->assertFalse($downloader->hasCachedFiles());

        mkdir($this->cachePath, 0755, true);
        file_put_contents($this->cachePath . '/test.md', 'content');

        $this->assertTrue($downloader->hasCachedFiles());
    }

    public function testMapCategoryDatabasePrefix(): void
    {
        $this->assertSame('guide_db', GitHubGuideDownloader::mapCategory('db-migrations.md'));
        $this->assertSame('guide_db', GitHubGuideDownloader::mapCategory('db-active-record.md'));
    }

    public function testMapCategorySecurityPrefix(): void
    {
        $this->assertSame('guide_security', GitHubGuideDownloader::mapCategory('security-authentication.md'));
    }

    public function testMapCategoryStartPrefix(): void
    {
        $this->assertSame('guide_start', GitHubGuideDownloader::mapCategory('start-installation.md'));
    }

    public function testMapCategoryConcept(): void
    {
        $this->assertSame('guide_concept', GitHubGuideDownloader::mapCategory('concept-behaviors.md'));
        $this->assertSame('guide_concept', GitHubGuideDownloader::mapCategory('concept-events.md'));
    }

    public function testMapCategoryUnknownPrefix(): void
    {
        // Unknown prefix extracts first segment
        $this->assertSame('guide_custom', GitHubGuideDownloader::mapCategory('custom-something.md'));
    }

    public function testMapCategoryNoHyphen(): void
    {
        $this->assertSame('guide_general', GitHubGuideDownloader::mapCategory('README.md'));
    }

    public function testGetCachePath(): void
    {
        $downloader = new GitHubGuideDownloader($this->cachePath);
        $this->assertSame($this->cachePath, $downloader->getCachePath());
    }

    public function testCreatesDirectoryAutomatically(): void
    {
        $nestedPath = $this->cachePath . '/nested/deep';
        $apiResponse = json_encode([
            ['name' => 'test.md', 'sha' => 'abc', 'type' => 'file'],
        ]);

        $downloader = new GitHubGuideDownloader($nestedPath);
        $downloader->setHttpFetcher(function (string $url) use ($apiResponse) {
            if (strpos($url, 'api.github.com') !== false) {
                return $apiResponse;
            }
            return "# Test\n\nContent.\n";
        });

        $result = $downloader->download();

        $this->assertSame(1, $result['downloaded']);
        $this->assertDirectoryExists($nestedPath);

        // Cleanup nested dirs
        @unlink($nestedPath . '/test.md');
        @rmdir($nestedPath);
        @rmdir($this->cachePath . '/nested');
    }
}
