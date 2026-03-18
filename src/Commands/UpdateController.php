<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Helpers\GuidelineWriter;
use codechap\yii2boost\Helpers\ProjectRootResolver;
use codechap\yii2boost\Mcp\Search\GitHubGuideDownloader;
use codechap\yii2boost\Mcp\Search\MarkdownSectionParser;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * Update Command
 *
 * Updates Yii2 AI Boost components: guidelines, skills, CLAUDE.md, and search index.
 *
 * Usage:
 *   php yii boost/update
 */
class UpdateController extends Controller
{
    /**
     * Update Yii2 AI Boost components
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("┌───────────────────────────────────────────┐\n", 32);
        $this->stdout("│   Yii2 AI Boost - Update                  │\n", 32);
        $this->stdout("└───────────────────────────────────────────┘\n\n", 32);

        try {
            $this->stdout("[1/5] Updating Guidelines\n", 33);
            $this->updateGuidelines();

            $this->stdout("\n[2/5] Updating Skills\n", 33);
            $this->updateSkills();

            $this->stdout("\n[3/5] Updating CLAUDE.md\n", 33);
            $this->updateClaudeMd();

            $this->stdout("\n[4/5] Fetching Yii2 Guide & Building Search Index\n", 33);
            $this->fetchYii2Guide();
            $this->buildSearchIndex();

            $this->stdout("\n[5/5] Verifying Installation\n", 33);
            $this->verifyInstallation();

            $this->stdout("\n", 0);
            $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n", 32);
            $this->stdout("Update Complete!\n", 32);
            $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n", 32);

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("✗ Update failed: " . $e->getMessage() . "\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Update guidelines from package source to .ai/guidelines/
     */
    private function updateGuidelines(): void
    {
        $appPath = ProjectRootResolver::resolve();
        $targetPath = $appPath . '/.ai/guidelines';
        $packageRoot = dirname(__DIR__, 2);
        $sourcePath = $packageRoot . '/.ai/guidelines';

        if (!is_dir($sourcePath)) {
            $this->stdout("  ! Package source guidelines not found at: $sourcePath\n", 33);
            return;
        }

        if (!is_dir($targetPath)) {
            FileHelper::createDirectory($targetPath);
        }

        try {
            FileHelper::copyDirectory($sourcePath, $targetPath, [
                'dirMode' => 0755,
                'fileMode' => 0644,
            ]);
            $this->stdout("  ✓ Guidelines updated\n", 32);
        } catch (\Exception $e) {
            throw new \Exception("Failed to copy guidelines: " . $e->getMessage());
        }
    }

    /**
     * Update skills from package to .ai/skills/ and .claude/skills/
     */
    private function updateSkills(): void
    {
        $appPath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);
        $sourcePath = $packageRoot . '/.ai/skills';
        $aiSkillsPath = $appPath . '/.ai/skills';
        $claudeSkillsPath = $appPath . '/.claude/skills';

        if (!is_dir($sourcePath)) {
            $this->stdout("  ! Package source skills not found at: $sourcePath\n", 33);
            return;
        }

        // Ensure directories exist
        foreach ([$aiSkillsPath, $claudeSkillsPath] as $dir) {
            if (!is_dir($dir)) {
                FileHelper::createDirectory($dir);
            }
        }

        try {
            // Copy to .ai/skills/ (source for search indexing)
            FileHelper::copyDirectory($sourcePath, $aiSkillsPath, [
                'dirMode' => 0755,
                'fileMode' => 0644,
            ]);

            // Copy to .claude/skills/ (for agent skill activation)
            FileHelper::copyDirectory($sourcePath, $claudeSkillsPath, [
                'dirMode' => 0755,
                'fileMode' => 0644,
            ]);

            $skillDirs = glob($claudeSkillsPath . '/*/SKILL.md');
            $count = is_array($skillDirs) ? count($skillDirs) : 0;
            $this->stdout("  ✓ Updated {$count} skills\n", 32);
        } catch (\Exception $e) {
            throw new \Exception("Failed to copy skills: " . $e->getMessage());
        }

        // Remove stale skills that no longer exist in source
        $this->cleanStaleSkills($sourcePath, $claudeSkillsPath);
        $this->cleanStaleSkills($sourcePath, $aiSkillsPath);
    }

    /**
     * Remove skill directories that no longer exist in source.
     *
     * @param string $sourcePath Source skills directory
     * @param string $targetPath Target skills directory
     */
    private function cleanStaleSkills(string $sourcePath, string $targetPath): void
    {
        if (!is_dir($targetPath)) {
            return;
        }

        $sourceSkills = array_map('basename', glob($sourcePath . '/*', GLOB_ONLYDIR) ?: []);
        $targetSkills = glob($targetPath . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($targetSkills as $targetDir) {
            $name = basename($targetDir);
            if (!in_array($name, $sourceSkills, true)) {
                FileHelper::removeDirectory($targetDir);
                $this->stdout("  ✓ Removed stale skill: {$name}\n", 33);
            }
        }
    }

    /**
     * Regenerate CLAUDE.md guidelines block (preserves user content)
     */
    private function updateClaudeMd(): void
    {
        $appPath = ProjectRootResolver::resolve();
        $guidelinePath = $appPath . '/.ai/guidelines/yii2-boost.md';
        $claudeMdPath = $appPath . '/CLAUDE.md';

        if (!file_exists($guidelinePath)) {
            $this->stdout("  ! Guideline file not found, skipping CLAUDE.md\n", 33);
            return;
        }

        $guidelineContent = file_get_contents($guidelinePath);

        // Discover installed skills for the activation section
        $skills = GuidelineWriter::discoverSkills($appPath . '/.ai/skills');

        if (GuidelineWriter::write($claudeMdPath, $guidelineContent, $skills)) {
            $this->stdout("  ✓ Updated CLAUDE.md guidelines block\n", 32);
            if (!empty($skills)) {
                $this->stdout("  ✓ Updated skills activation section (" . count($skills) . " skills)\n", 32);
            }
        } else {
            $this->stdout("  ✓ CLAUDE.md already up-to-date\n", 32);
        }
    }

    /**
     * Fetch Yii2 definitive guide from GitHub
     */
    private function fetchYii2Guide(): void
    {
        $appPath = ProjectRootResolver::resolve();
        $cachePath = $appPath . '/.ai/yii2-guide';

        $downloader = new GitHubGuideDownloader($cachePath);

        $this->stdout("  Downloading from GitHub...\n", 0);

        $result = $downloader->download();

        if ($result['downloaded'] > 0) {
            $this->stdout("  ✓ Downloaded {$result['downloaded']} guide files\n", 32);
        }
        if ($result['skipped'] > 0) {
            $this->stdout("  ✓ {$result['skipped']} files already up-to-date\n", 32);
        }
        if ($result['failed'] > 0) {
            $this->stdout("  ! {$result['failed']} files failed to download\n", 33);
        }
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->stdout("  ! $error\n", 33);
            }
        }

        if ($result['downloaded'] === 0 && $result['skipped'] === 0 && !$downloader->hasCachedFiles()) {
            $this->stdout("  ! Guide download failed. Bundled content will still be indexed.\n", 33);
        }
    }

    /**
     * Build the FTS5 search index
     */
    private function buildSearchIndex(): void
    {
        if (!SearchIndexManager::isFts5Available()) {
            $this->stdout("  ! FTS5 extension not available. Search index not built.\n", 33);
            return;
        }

        $appPath = ProjectRootResolver::resolve();
        $dbPath = Yii::getAlias('@runtime') . '/boost/search.db';

        $manager = new SearchIndexManager($dbPath);
        $manager->createSchema();
        $manager->clearIndex();

        $parser = new MarkdownSectionParser();
        $totalSections = 0;

        // Index guidelines
        $guidelinesPath = $appPath . '/.ai/guidelines';
        if (is_dir($guidelinesPath)) {
            $files = FileHelper::findFiles($guidelinesPath, [
                'only' => ['*.md'],
                'recursive' => true,
            ]);

            foreach ($files as $file) {
                $relativePath = str_replace($appPath . '/.ai/guidelines/', '', $file);
                $content = file_get_contents($file);
                $parsed = $parser->parse($content, $file);

                $count = $manager->indexSections(
                    'bundled',
                    'guidelines',
                    $relativePath,
                    $parsed['file_title'],
                    $parsed['sections']
                );
                $totalSections += $count;
            }

            $this->stdout("  ✓ Indexed guidelines: {$totalSections} sections\n", 32);
        }

        // Index skills
        $skillsSections = 0;
        $skillsPath = $appPath . '/.ai/skills';
        if (is_dir($skillsPath)) {
            $files = FileHelper::findFiles($skillsPath, [
                'only' => ['*.md'],
                'recursive' => true,
            ]);

            foreach ($files as $file) {
                $relativePath = str_replace($appPath . '/.ai/skills/', '', $file);
                $skillName = dirname($relativePath);
                $content = file_get_contents($file);

                // Strip YAML frontmatter before parsing
                $content = $this->stripFrontmatter($content);

                $parsed = $parser->parse($content, $file);

                $count = $manager->indexSections(
                    'bundled',
                    'skills/' . $skillName,
                    $relativePath,
                    $parsed['file_title'],
                    $parsed['sections']
                );
                $skillsSections += $count;
            }

            $totalSections += $skillsSections;
            $this->stdout("  ✓ Indexed skills: {$skillsSections} sections\n", 32);
        }

        // Index Yii2 guide (if cached)
        $guideSections = 0;
        $guidePath = $appPath . '/.ai/yii2-guide';
        if (is_dir($guidePath)) {
            $guideDownloader = new GitHubGuideDownloader($guidePath);
            $guideFiles = $guideDownloader->getCachedFiles();

            foreach ($guideFiles as $file) {
                $filename = basename($file);
                $category = GitHubGuideDownloader::mapCategory($filename);
                $content = file_get_contents($file);
                $parsed = $parser->parse($content, $file);

                $count = $manager->indexSections(
                    'yii2_guide',
                    $category,
                    $filename,
                    $parsed['file_title'],
                    $parsed['sections']
                );
                $guideSections += $count;
            }

            $totalSections += $guideSections;
            $this->stdout("  ✓ Indexed Yii2 guide: {$guideSections} sections\n", 32);
        }

        $manager->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        $manager->setMeta('section_count', (string) $totalSections);

        $this->stdout("  ✓ Search index built: {$totalSections} total sections\n", 32);
    }

    /**
     * Strip YAML frontmatter from markdown content.
     *
     * @param string $content Markdown content
     * @return string Content without frontmatter
     */
    private function stripFrontmatter(string $content): string
    {
        if (strpos($content, "---\n") === 0) {
            $end = strpos($content, "\n---\n", 4);
            if ($end !== false) {
                return ltrim(substr($content, $end + 5));
            }
        }
        return $content;
    }

    /**
     * Verify installation
     */
    private function verifyInstallation(): void
    {
        $basePath = ProjectRootResolver::resolve();

        $checks = [
            'CLAUDE.md' => file_exists($basePath . '/CLAUDE.md'),
            '.mcp.json' => file_exists($basePath . '/.mcp.json'),
            '.ai/guidelines/' => is_dir($basePath . '/.ai/guidelines'),
            '.ai/skills/' => is_dir($basePath . '/.ai/skills'),
            '.claude/skills/' => is_dir($basePath . '/.claude/skills'),
        ];

        foreach ($checks as $name => $exists) {
            if ($exists) {
                $this->stdout("  ✓ $name\n", 32);
            } else {
                $this->stdout("  ✗ $name missing\n", 31);
            }
        }

        // Check CLAUDE.md has guidelines block
        $claudeMdPath = $basePath . '/CLAUDE.md';
        if (GuidelineWriter::hasGuidelines($claudeMdPath)) {
            $this->stdout("  ✓ CLAUDE.md contains guidelines block\n", 32);
        } else {
            $this->stdout("  ✗ CLAUDE.md missing guidelines block\n", 31);
        }

        // Check skills count
        $skillDirs = glob($basePath . '/.claude/skills/*/SKILL.md');
        $count = is_array($skillDirs) ? count($skillDirs) : 0;
        $this->stdout("  ✓ {$count} skills installed\n", 32);

        // Check search index
        $searchDb = Yii::getAlias('@runtime') . '/boost/search.db';
        if (file_exists($searchDb)) {
            $sizeKb = round(filesize($searchDb) / 1024, 1);
            $this->stdout("  ✓ Search index ({$sizeKb}KB)\n", 32);
        } else {
            $this->stdout("  ✗ Search index not built\n", 31);
        }
    }
}
