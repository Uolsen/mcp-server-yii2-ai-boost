<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Helpers\GuidelineWriter;
use codechap\yii2boost\Helpers\ProjectRootResolver;
use codechap\yii2boost\Mcp\Search\MarkdownSectionParser;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * Install Command
 *
 * Installs and configures Yii2 AI Boost in the application.
 * Creates CLAUDE.md with guidelines, installs skills to .claude/skills/,
 * copies source files to .ai/, and builds the search index.
 *
 * Usage:
 *   php yii boost/install
 */
class InstallController extends Controller
{
    /**
     * Install Yii2 AI Boost in the application
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("┌───────────────────────────────────────────┐\n", 32);
        $this->stdout("│      Yii2 AI Boost Installation Wizard    │\n", 32);
        $this->stdout("└───────────────────────────────────────────┘\n\n", 32);

        try {
            // Step 1: Detect environment
            $this->stdout("[1/5] Detecting Environment\n", 33);
            $envInfo = $this->detectEnvironment();
            $this->outputEnvironmentInfo($envInfo);

            // Step 2: Create directories
            $this->stdout("\n[2/5] Creating Directories\n", 33);
            $this->createDirectories();

            // Step 3: Generate MCP configuration
            $this->stdout("\n[3/5] Generating MCP Configuration\n", 33);
            $this->generateConfigFiles($envInfo);

            // Step 4: Install skills to .claude/skills/ and write CLAUDE.md
            $this->stdout("\n[4/5] Installing Skills & Writing CLAUDE.md\n", 33);
            $this->installSkills();
            $this->writeClaudeMd();

            // Step 5: Build search index
            $this->stdout("\n[5/5] Building Search Index\n", 33);
            $this->buildSearchIndex();

            // Success message
            $this->outputSuccessMessage($envInfo);

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("✗ Installation failed: " . $e->getMessage() . "\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Detect application environment
     *
     * @return array
     */
    private function detectEnvironment(): array
    {
        $app = Yii::$app;
        $projectRoot = ProjectRootResolver::resolve();
        $isAdvancedApp = ProjectRootResolver::isAdvancedApp();

        return [
            'yii_version' => Yii::getVersion(),
            'php_version' => phpversion(),
            'app_base_path' => $app->getBasePath(),
            'project_root' => $projectRoot,
            'is_advanced_app' => $isAdvancedApp,
            'runtime_path' => Yii::getAlias('@runtime'),
            'yii_env' => YII_ENV,
            'yii_debug' => YII_DEBUG,
        ];
    }

    /**
     * Output environment detection results
     *
     * @param array $envInfo Environment information
     */
    private function outputEnvironmentInfo(array $envInfo): void
    {
        $this->stdout("  ✓ Yii2 version: {$envInfo['yii_version']}\n", 32);
        $this->stdout("  ✓ PHP version: {$envInfo['php_version']}\n", 32);
        $this->stdout("  ✓ Environment: {$envInfo['yii_env']}\n", 32);
        $this->stdout("  ✓ Debug mode: " . ($envInfo['yii_debug'] ? 'ON' : 'OFF') . "\n", 32);
        $appType = $envInfo['is_advanced_app'] ? 'Advanced' : 'Basic';
        $this->stdout("  ✓ App structure: {$appType}\n", 32);
        if ($envInfo['is_advanced_app']) {
            $this->stdout("  ✓ Project root: {$envInfo['project_root']}\n", 32);
        }
    }

    /**
     * Create necessary directories
     *
     * @throws \Exception
     */
    private function createDirectories(): void
    {
        $basePath = ProjectRootResolver::resolve();

        $directories = [
            $basePath . '/.ai',
            $basePath . '/.ai/skills',
            $basePath . '/.claude',
            $basePath . '/.claude/skills',
        ];

        $created = 0;
        $existed = 0;

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                FileHelper::createDirectory($dir);
                $this->stdout("  ✓ Created: $dir\n", 32);
                $created++;
            } else {
                $existed++;
            }
        }

        if ($created === 0 && $existed > 0) {
            $this->stdout("  ✓ All directories already exist\n", 32);
        }
    }

    /**
     * Generate MCP configuration files
     *
     * @param array $envInfo Environment information
     * @throws \Exception
     */
    private function generateConfigFiles(array $envInfo): void
    {
        $basePath = ProjectRootResolver::resolve();

        $phpPath = PHP_BINARY;
        $yiiPath = ProjectRootResolver::getYiiScriptPath($basePath) ?? $basePath . '/yii';

        $mcpConfig = [
            'mcpServers' => [
                'yii2-boost' => [
                    'command' => $phpPath,
                    'args' => [$yiiPath, 'boost/mcp'],
                    'env' => (object)[],
                ],
            ],
        ];

        $mcpConfigJson = json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->updateFileWithConfirmation(
            $basePath . '/.mcp.json',
            $mcpConfigJson,
            '.mcp.json'
        );

        $this->addToGitignore($basePath, '.mcp.json');
    }

    /**
     * Install skills to .claude/skills/ from vendor and project .ai/skills/
     *
     * Yii2-specific skills are read directly from the vendor package.
     * Project-specific skills live in .ai/skills/ and are also synced.
     */
    private function installSkills(): void
    {
        $appBasePath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);
        $vendorSkillsPath = $packageRoot . '/.ai/skills';
        $projectSkillsPath = $appBasePath . '/.ai/skills';
        $claudeSkillsPath = $appBasePath . '/.claude/skills';

        // Copy vendor (Yii2) skills to .claude/skills/
        if (is_dir($vendorSkillsPath)) {
            try {
                FileHelper::copyDirectory($vendorSkillsPath, $claudeSkillsPath, [
                    'dirMode' => 0755,
                    'fileMode' => 0644,
                ]);
            } catch (\Exception $e) {
                $this->stderr("  ✗ Failed to copy vendor skills: " . $e->getMessage() . "\n", 31);
                return;
            }
        } else {
            $this->stdout("  ! Vendor skills source not found at: $vendorSkillsPath\n", 33);
        }

        // Copy project-specific skills to .claude/skills/
        if (is_dir($projectSkillsPath)) {
            $projectSkillDirs = glob($projectSkillsPath . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($projectSkillDirs as $skillDir) {
                $name = basename($skillDir);
                $targetDir = $claudeSkillsPath . '/' . $name;
                if (!is_dir($targetDir)) {
                    FileHelper::copyDirectory($skillDir, $targetDir, [
                        'dirMode' => 0755,
                        'fileMode' => 0644,
                    ]);
                }
            }
        }

        // Count installed skills
        $skillDirs = glob($claudeSkillsPath . '/*/SKILL.md');
        $count = is_array($skillDirs) ? count($skillDirs) : 0;
        $this->stdout("  ✓ Installed {$count} skills to .claude/skills/\n", 32);
    }

    /**
     * Write guidelines to CLAUDE.md
     *
     * Reads guidelines from the vendor package and embeds them in the
     * <yii2-boost-guidelines> block. Skills are discovered from both
     * vendor and project .ai/skills/.
     */
    private function writeClaudeMd(): void
    {
        $appBasePath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);
        $vendorGuidelinesPath = $packageRoot . '/.ai/guidelines';
        $claudeMdPath = $appBasePath . '/CLAUDE.md';

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
            $this->stdout("  ✓ Updated CLAUDE.md with guidelines\n", 32);
            if (!empty($skills)) {
                $this->stdout("  ✓ Added skills activation section (" . count($skills) . " skills)\n", 32);
            }
        } else {
            $this->stdout("  ✓ CLAUDE.md already up-to-date\n", 32);
        }

        $this->addToGitignore($appBasePath, '# Yii2 AI Boost');
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
        $appBasePath = ProjectRootResolver::resolve();
        $packageRoot = dirname(__DIR__, 2);

        $vendorSkills = GuidelineWriter::discoverSkills($packageRoot . '/.ai/skills');
        $projectSkills = GuidelineWriter::discoverSkills($appBasePath . '/.ai/skills');

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
     * Build the FTS5 search index from vendor guidelines/skills and project skills
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
                $totalSections += $count;
            }
        }

        // Index skills from vendor
        $totalSections += $this->indexSkillsFromPath($manager, $parser, $packageRoot . '/.ai/skills');

        // Index project-specific skills
        $totalSections += $this->indexSkillsFromPath($manager, $parser, $appPath . '/.ai/skills');

        $manager->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        $manager->setMeta('section_count', (string) $totalSections);

        $this->stdout("  ✓ Search index built: {$totalSections} sections\n", 32);
        $this->stdout("  Tip: Run 'php yii boost/update' to also index the Yii2 guide.\n", 33);
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
     * Update file with user confirmation
     *
     * @param string $filePath File path to update
     * @param string $newContent New file content
     * @param string $description Description of the file
     * @return bool Whether the file was updated
     */
    private function updateFileWithConfirmation(string $filePath, string $newContent, string $description): bool
    {
        $exists = file_exists($filePath);

        if ($exists) {
            $oldContent = file_get_contents($filePath);
            if ($oldContent === $newContent) {
                $this->stdout("  ✓ $description already up-to-date\n", 32);
                return false;
            }
        }

        $this->stdout("\nProposed update to $description:\n", 33);
        $this->stdout("────────────────────────────────────\n", 33);
        $this->stdout($newContent, 0);
        $this->stdout("────────────────────────────────────\n", 33);

        if ($this->confirm("Apply this update to $description?")) {
            file_put_contents($filePath, $newContent);
            $this->stdout("  ✓ Updated $description\n", 32);
            return true;
        } else {
            $this->stdout("  - Skipped updating $description\n", 33);
            return false;
        }
    }

    /**
     * Add entry to .gitignore
     *
     * @param string $basePath Application base path
     * @param string $entry Entry to add
     */
    private function addToGitignore(string $basePath, string $entry): void
    {
        $gitignore = $basePath . '/.gitignore';

        if (file_exists($gitignore)) {
            $content = file_get_contents($gitignore);
            if (stripos($content, $entry) === false) {
                $this->stdout("\nProposed update to .gitignore:\n", 33);
                $this->stdout("  + $entry\n", 32);

                if ($this->confirm("Add $entry to .gitignore?")) {
                    file_put_contents($gitignore, "\n$entry\n", FILE_APPEND);
                    $this->stdout("  ✓ Added $entry to .gitignore\n", 32);
                } else {
                    $this->stdout("  - Skipped adding to .gitignore\n", 33);
                }
            } else {
                $this->stdout("  ✓ .gitignore already contains $entry\n", 32);
            }
        } else {
            file_put_contents($gitignore, "$entry\n");
            $this->stdout("  ✓ Created .gitignore with $entry\n", 32);
        }
    }

    /**
     * Output success message
     *
     * @param array $envInfo Environment information
     */
    private function outputSuccessMessage(array $envInfo): void
    {
        $this->stdout("\n", 0);
        $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n", 32);
        $this->stdout("Installation Complete!\n", 32);
        $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n", 32);

        $this->stdout("What was installed:\n", 36);
        $this->stdout("  • CLAUDE.md - Guidelines (always loaded by AI agent)\n", 0);
        $this->stdout("  • .claude/skills/ - Skills (loaded on-demand when relevant)\n", 0);
        $this->stdout("  • .mcp.json - MCP server configuration\n", 0);
        $this->stdout("  • .ai/skills/ - For project-specific skills\n\n", 0);

        $this->stdout("How it works:\n", 36);
        $this->stdout("  • Yii2 guidelines & skills are loaded from the vendor package\n", 0);
        $this->stdout("  • Project-specific skills go in .ai/skills/\n", 0);
        $this->stdout("  • Skills are activated automatically when the task is relevant\n", 0);
        $this->stdout("  • MCP tools provide live introspection of your application\n\n", 0);

        $this->stdout("Commands:\n", 36);
        $this->stdout("  php yii boost/update   - Update guidelines, skills & search index\n", 37);
        $this->stdout("  php yii boost/info     - View installation status\n", 37);
        $this->stdout("  php yii boost/mcp      - Start MCP server (for testing)\n\n", 37);
    }
}
