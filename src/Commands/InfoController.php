<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Helpers\GuidelineWriter;
use codechap\yii2boost\Helpers\ProjectRootResolver;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Info Command
 *
 * Displays information about Yii2 AI Boost installation and configuration.
 *
 * Usage:
 *   php yii boost/info
 */
class InfoController extends Controller
{
    /**
     * Display Yii2 AI Boost information
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("╔════════════════════════════════════════╗\n", 36);
        $this->stdout("║    Yii2 AI Boost - Information         ║\n", 36);
        $this->stdout("╚════════════════════════════════════════╝\n\n", 36);

        try {
            $this->displayPackageInfo();
            $this->displayConfigStatus();
            $this->displayTools();
            $this->displayGuidelines();
            $this->displaySkills();
            $this->displaySearchIndex();

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("✗ Error: " . $e->getMessage() . "\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Display package information
     */
    private function displayPackageInfo(): void
    {
        $this->stdout("Package Information\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $this->stdout("  Version: " . \codechap\yii2boost\Mcp\Server::VERSION . "\n", 32);
        $this->stdout("  Yii2 Version: " . Yii::getVersion() . "\n", 32);
        $this->stdout("  PHP Version: " . phpversion() . "\n", 32);
        $this->stdout("  Environment: " . YII_ENV . "\n", 32);

        $this->stdout("\n", 0);
    }

    /**
     * Display configuration status
     */
    private function displayConfigStatus(): void
    {
        $basePath = ProjectRootResolver::resolve();

        $this->stdout("Configuration Status\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $checks = [
            '.mcp.json' => 'MCP server configuration',
            'CLAUDE.md' => 'CLAUDE.md file',
            '.ai/guidelines' => 'Guidelines directory',
            '.ai/skills' => 'Skills source directory',
            '.claude/skills' => 'Agent skills directory',
        ];

        foreach ($checks as $file => $description) {
            $path = $basePath . '/' . $file;
            if (file_exists($path) || is_dir($path)) {
                $this->stdout("  ✓ $description\n", 32);
            } else {
                $this->stdout("  ✗ $description (missing)\n", 31);
            }
        }

        // Check if CLAUDE.md contains guidelines block
        $claudeMdPath = $basePath . '/CLAUDE.md';
        if (file_exists($claudeMdPath)) {
            if (GuidelineWriter::hasGuidelines($claudeMdPath)) {
                $this->stdout("  ✓ CLAUDE.md contains guidelines block\n", 32);
            } else {
                $this->stdout("  ✗ CLAUDE.md missing guidelines block\n", 31);
            }
        }

        $this->stdout("\n", 0);
    }

    /**
     * Display available tools
     */
    private function displayTools(): void
    {
        $this->stdout("Available Tools\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $server = new \codechap\yii2boost\Mcp\Server([
            'basePath' => Yii::$app->getBasePath(),
            'projectRoot' => ProjectRootResolver::resolve(),
        ]);
        $tools = $server->getTools();

        foreach ($tools as $name => $tool) {
            $this->stdout("  • $name\n", 36);
            $this->stdout("    " . $tool->getDescription() . "\n", 0);
        }

        $this->stdout("\nTotal: " . count($tools) . " tools available\n\n", 32);
    }

    /**
     * Display guidelines status
     */
    private function displayGuidelines(): void
    {
        $basePath = ProjectRootResolver::resolve();
        $guidelinesPath = $basePath . '/.ai/guidelines';

        $this->stdout("Guidelines\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        if (!is_dir($guidelinesPath)) {
            $this->stdout("  ✗ Guidelines directory not found\n", 31);
            $this->stdout("\n", 0);
            return;
        }

        $guidelineFile = $guidelinesPath . '/yii2-boost.md';
        if (file_exists($guidelineFile)) {
            $sizeKb = round(filesize($guidelineFile) / 1024, 1);
            $this->stdout("  ✓ yii2-boost.md ({$sizeKb}KB) — embedded in CLAUDE.md\n", 32);
        } else {
            $this->stdout("  ✗ yii2-boost.md not found\n", 31);
        }

        $this->stdout("\n", 0);
    }

    /**
     * Display skills status
     */
    private function displaySkills(): void
    {
        $basePath = ProjectRootResolver::resolve();
        $claudeSkillsPath = $basePath . '/.claude/skills';

        $this->stdout("Skills (on-demand)\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        if (!is_dir($claudeSkillsPath)) {
            $this->stdout("  ✗ Skills directory not found (.claude/skills/)\n", 31);
            $this->stdout("\n", 0);
            return;
        }

        $skillFiles = glob($claudeSkillsPath . '/*/SKILL.md');
        if (empty($skillFiles)) {
            $this->stdout("  ✗ No skills installed\n", 31);
            $this->stdout("\n", 0);
            return;
        }

        foreach ($skillFiles as $skillFile) {
            $skillName = basename(dirname($skillFile));
            $sizeKb = round(filesize($skillFile) / 1024, 1);
            $this->stdout("  ✓ {$skillName} ({$sizeKb}KB)\n", 32);
        }

        $this->stdout("\nTotal: " . count($skillFiles) . " skills installed\n\n", 32);
    }

    /**
     * Display search index status
     */
    private function displaySearchIndex(): void
    {
        $this->stdout("Search Index\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $searchDb = Yii::getAlias('@runtime') . '/boost/search.db';

        if (!file_exists($searchDb)) {
            $this->stdout("  ✗ Not built (run 'php yii boost/update')\n", 31);
            $this->stdout("\n", 0);
            return;
        }

        try {
            $manager = new SearchIndexManager($searchDb);
            $stats = $manager->getStats();

            $sizeKb = round(filesize($searchDb) / 1024, 1);
            $this->stdout("  ✓ Index size: {$sizeKb}KB\n", 32);
            $this->stdout("  ✓ Total sections: {$stats['total_sections']}\n", 32);
            $this->stdout("  ✓ Last rebuild: {$stats['last_rebuild']}\n", 32);

            if (!empty($stats['sources'])) {
                foreach ($stats['sources'] as $source => $count) {
                    $label = $source === 'yii2_guide' ? 'Yii2 Guide' : 'Bundled';
                    $this->stdout("    - {$label}: {$count} sections\n", 36);
                }
            }
        } catch (\Exception $e) {
            $this->stdout("  ! Error reading index: " . $e->getMessage() . "\n", 33);
        }

        $this->stdout("\n", 0);
    }
}
