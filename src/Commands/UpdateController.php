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
            $this->stdout("[1/3] Updating Skills & CLAUDE.md\n", 33);
            $this->updateSkills();
            $this->updateClaudeMd();

            $this->stdout("\n[2/3] Fetching Yii2 Guide & Building Search Index\n", 33);
            $this->fetchYii2Guide();
            $this->buildSearchIndex();

            $this->stdout("\n[3/3] Verifying Installation\n", 33);
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
     * Update skills in .claude/skills/ from vendor and project .ai/skills/
     *
     * Yii2-specific skills are read directly from the vendor package.
     * Project-specific skills live in .ai/skills/ and are also synced.
     */
    private function updateSkills(): void
    {
        $appPath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);
        $vendorSkillsPath = $packageRoot . '/.ai/skills';
        $projectSkillsPath = $appPath . '/.ai/skills';
        $claudeSkillsPath = $appPath . '/.claude/skills';

        // Ensure .claude/skills/ exists
        if (!is_dir($claudeSkillsPath)) {
            FileHelper::createDirectory($claudeSkillsPath);
        }

        // Collect valid skill names from vendor + project
        $validSkillNames = [];

        // Copy vendor (Yii2) skills to .claude/skills/
        if (is_dir($vendorSkillsPath)) {
            try {
                FileHelper::copyDirectory($vendorSkillsPath, $claudeSkillsPath, [
                    'dirMode' => 0755,
                    'fileMode' => 0644,
                ]);
                $vendorNames = array_map('basename', glob($vendorSkillsPath . '/*', GLOB_ONLYDIR) ?: []);
                $validSkillNames = array_merge($validSkillNames, $vendorNames);
            } catch (\Exception $e) {
                throw new \Exception("Failed to copy vendor skills: " . $e->getMessage());
            }
        }

        // Copy project-specific skills to .claude/skills/
        if (is_dir($projectSkillsPath)) {
            $projectSkillDirs = glob($projectSkillsPath . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($projectSkillDirs as $skillDir) {
                $name = basename($skillDir);
                $validSkillNames[] = $name;
                $targetDir = $claudeSkillsPath . '/' . $name;
                try {
                    FileHelper::copyDirectory($skillDir, $targetDir, [
                        'dirMode' => 0755,
                        'fileMode' => 0644,
                    ]);
                } catch (\Exception $e) {
                    $this->stderr("  ✗ Failed to copy project skill {$name}: " . $e->getMessage() . "\n", 31);
                }
            }
        }

        // Remove stale skills from .claude/skills/ that no longer exist in vendor or project
        $claudeSkills = glob($claudeSkillsPath . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($claudeSkills as $claudeDir) {
            $name = basename($claudeDir);
            if (!in_array($name, $validSkillNames, true)) {
                FileHelper::removeDirectory($claudeDir);
                $this->stdout("  ✓ Removed stale skill: {$name}\n", 33);
            }
        }

        $skillDirs = glob($claudeSkillsPath . '/*/SKILL.md');
        $count = is_array($skillDirs) ? count($skillDirs) : 0;
        $this->stdout("  ✓ Updated {$count} skills in .claude/skills/\n", 32);
    }

    /**
     * Regenerate CLAUDE.md guidelines block (preserves user content)
     *
     * Reads guidelines from the vendor package and embeds them in the
     * <yii2-boost-guidelines> block. Skills are discovered from both
     * vendor and project .ai/skills/.
     */
    private function updateClaudeMd(): void
    {
        $appPath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);
        $vendorGuidelinesPath = $packageRoot . '/.ai/guidelines';
        $claudeMdPath = $appPath . '/CLAUDE.md';

        if (!is_dir($vendorGuidelinesPath)) {
            $this->stdout("  ! Vendor guidelines not found, skipping CLAUDE.md\n", 33);
            return;
        }

        $files = FileHelper::findFiles($vendorGuidelinesPath, [
            'only' => ['*.md'],
            'recursive' => false,
        ]);

        if (empty($files)) {
            $this->stdout("  ! No guideline files found, skipping CLAUDE.md\n", 33);
            return;
        }

        // Sort: yii2-boost.md first, then alphabetically
        usort($files, static function (string $a, string $b): int {
            $aBase = basename($a);
            $bBase = basename($b);
            if ($aBase === 'yii2-boost.md') {
                return -1;
            }
            if ($bBase === 'yii2-boost.md') {
                return 1;
            }
            return strcmp($aBase, $bBase);
        });

        $parts = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $parts[] = trim($content);
                $this->stdout("  ✓ Including: " . basename($file) . "\n", 32);
            }
        }

        if (empty($parts)) {
            $this->stdout("  ! No guideline content found, skipping CLAUDE.md\n", 33);
            return;
        }

        $guidelineContent = implode("\n\n---\n\n", $parts);

        // Discover skills from vendor and project
        $skills = $this->discoverAllSkills();

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
     * Discover skills from both vendor package and project .ai/skills/.
     *
     * Project skills override vendor skills with the same name.
     *
     * @return array<array{name: string, description: string}>
     */
    private function discoverAllSkills(): array
    {
        $appPath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);

        $vendorSkills = GuidelineWriter::discoverSkills($packageRoot . '/.ai/skills');
        $projectSkills = GuidelineWriter::discoverSkills($appPath . '/.ai/skills');

        // Merge: project skills override vendor skills by name
        $merged = [];
        foreach ($vendorSkills as $skill) {
            $merged[$skill['name']] = $skill;
        }
        foreach ($projectSkills as $skill) {
            $merged[$skill['name']] = $skill;
        }

        return array_values($merged);
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
     * Build the FTS5 search index from vendor, project skills, and Yii2 guide
     */
    private function buildSearchIndex(): void
    {
        if (!SearchIndexManager::isFts5Available()) {
            $this->stdout("  ! FTS5 extension not available. Search index not built.\n", 33);
            return;
        }

        $appPath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);
        $dbPath = Yii::getAlias('@runtime') . '/boost/search.db';

        $manager = new SearchIndexManager($dbPath);
        $manager->createSchema();
        $manager->clearIndex();

        $parser = new MarkdownSectionParser();
        $totalSections = 0;

        // Index guidelines from vendor
        $guidelinesPath = $packageRoot . '/.ai/guidelines';
        if (is_dir($guidelinesPath)) {
            $files = FileHelper::findFiles($guidelinesPath, [
                'only' => ['*.md'],
                'recursive' => true,
            ]);

            $guidelineSections = 0;
            foreach ($files as $file) {
                $relativePath = str_replace($guidelinesPath . '/', '', $file);
                $content = file_get_contents($file);
                $parsed = $parser->parse($content, $file);

                $count = $manager->indexSections(
                    'bundled',
                    'guidelines',
                    $relativePath,
                    $parsed['file_title'],
                    $parsed['sections']
                );
                $guidelineSections += $count;
            }

            $totalSections += $guidelineSections;
            $this->stdout("  ✓ Indexed guidelines: {$guidelineSections} sections\n", 32);
        }

        // Index skills from vendor
        $vendorSkillSections = $this->indexSkillsFromPath($manager, $parser, $packageRoot . '/.ai/skills');
        $totalSections += $vendorSkillSections;

        // Index project-specific skills
        $projectSkillSections = $this->indexSkillsFromPath($manager, $parser, $appPath . '/.ai/skills');
        $totalSections += $projectSkillSections;

        $this->stdout("  ✓ Indexed skills: " . ($vendorSkillSections + $projectSkillSections) . " sections\n", 32);

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
     * Index skills from a given directory path.
     *
     * @param SearchIndexManager $manager Search index manager
     * @param MarkdownSectionParser $parser Markdown parser
     * @param string $skillsPath Path to skills directory
     * @return int Number of sections indexed
     */
    private function indexSkillsFromPath(
        SearchIndexManager $manager,
        MarkdownSectionParser $parser,
        string $skillsPath
    ): int {
        if (!is_dir($skillsPath)) {
            return 0;
        }

        $files = FileHelper::findFiles($skillsPath, [
            'only' => ['*.md'],
            'recursive' => true,
        ]);

        $totalSections = 0;
        foreach ($files as $file) {
            $relativePath = str_replace($skillsPath . '/', '', $file);
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
            $totalSections += $count;
        }

        return $totalSections;
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
