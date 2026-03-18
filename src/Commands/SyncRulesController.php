<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Helpers\ProjectRootResolver;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * Syncs AI guidelines and skills to editor configurations (Cursor, Zed).
 */
class SyncRulesController extends Controller
{
    /**
     * @var string Path to the vendor package root
     */
    private $packageRoot;

    /**
     * @var string Path to the project .ai directory
     */
    private $aiPath;

    /**
     * @var string Path to the .cursor/rules directory
     */
    private $cursorRulesPath;

    /**
     * @var string Path to the .rules file (for Zed editor)
     */
    private $zedRulesPath;

    public function init(): void
    {
        parent::init();
        $root = ProjectRootResolver::resolve();
        $this->packageRoot = dirname(__DIR__, 2);
        $this->aiPath = $root . '/.ai';
        $this->cursorRulesPath = $root . '/.cursor/rules';
        $this->zedRulesPath = $root . '/.rules';
    }

    /**
     * Syncs guidelines and skills to .cursor/rules/yii2-boost.mdc and .rules (Zed)
     */
    public function actionIndex(): int
    {
        $this->stdout("Syncing Yii2 Boost rules to editor configurations...\n", 36);

        $guidelinePath = $this->packageRoot . '/.ai/guidelines/yii2-boost.md';
        if (!file_exists($guidelinePath)) {
            $this->stderr("Error: Vendor guidelines not found at {$guidelinePath}\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $content = $this->generateMdcContent($guidelinePath);

        // Show preview before asking for confirmation
        $this->stdout("\nProposed rules content preview (first 800 characters):\n", 33);
        $this->stdout("────────────────────────────────────────────────────────\n", 33);
        $this->stdout(substr($content, 0, 800) . (strlen($content) > 800 ? "\n... (content continues) ...\n" : ""), 0);
        $this->stdout("────────────────────────────────────────────────────────\n", 33);

        if (!$this->confirm("Proceed with syncing rules to editor configurations?")) {
            $this->stdout("  - Sync cancelled\n", 33);
            return ExitCode::OK;
        }

        $this->stdout("\n");

        // 1. Sync Cursor Rules
        if (!is_dir($this->cursorRulesPath)) {
            FileHelper::createDirectory($this->cursorRulesPath);
            $this->stdout("  ✓ Created .cursor/rules directory\n", 32);
        }

        $cursorOutputFile = $this->cursorRulesPath . '/yii2-boost.mdc';
        file_put_contents($cursorOutputFile, $content);
        $this->stdout("  ✓ Synced Cursor rules to {$cursorOutputFile}\n", 32);

        // 2. Sync Zed Rules
        file_put_contents($this->zedRulesPath, $content);
        $this->stdout("  ✓ Synced Zed rules to {$this->zedRulesPath}\n", 32);

        return ExitCode::OK;
    }

    /**
     * Generates the content for the MDC file.
     *
     * Includes the condensed guideline and a summary of available skills.
     *
     * @param string $guidelinePath Path to the yii2-boost.md guideline
     * @return string
     */
    private function generateMdcContent(string $guidelinePath): string
    {
        $mdc = "# Yii2 Framework Guidelines (Boost)\n\n";
        $mdc .= "You are an expert Yii2 developer working in a Yii 2.0.45 advanced template application.\n";
        $mdc .= "Follow these strict guidelines and structural references.\n\n";

        // 1. Core guidelines (always loaded)
        $mdc .= "## Core Guidelines\n\n";
        $mdc .= file_get_contents($guidelinePath) . "\n\n";

        // 2. List available skills from vendor and project
        $skillSources = [
            $this->packageRoot . '/.ai/skills',
            $this->aiPath . '/skills',
        ];

        $skillEntries = [];
        foreach ($skillSources as $skillsPath) {
            if (!is_dir($skillsPath)) {
                continue;
            }
            $skillDirs = glob($skillsPath . '/*/SKILL.md');
            if (empty($skillDirs)) {
                continue;
            }
            foreach ($skillDirs as $skillFile) {
                $skillName = basename(dirname($skillFile));
                // Project skills override vendor skills with the same name
                $skillEntries[$skillName] = $this->extractSkillDescription($skillFile);
            }
        }

        if (!empty($skillEntries)) {
            ksort($skillEntries);
            $mdc .= "## Available Skills Reference\n\n";
            $mdc .= "The following detailed skill references are available in `.claude/skills/` ";
            $mdc .= "and will be activated automatically when relevant:\n\n";

            foreach ($skillEntries as $skillName => $description) {
                $mdc .= "- **{$skillName}**: {$description}\n";
            }
            $mdc .= "\n";
        }

        return $mdc;
    }

    /**
     * Extract the description from a SKILL.md frontmatter.
     *
     * @param string $skillFile Path to the SKILL.md file
     * @return string Description or fallback text
     */
    private function extractSkillDescription(string $skillFile): string
    {
        $content = file_get_contents($skillFile);

        if (strpos($content, "---\n") === 0) {
            $end = strpos($content, "\n---\n", 4);
            if ($end !== false) {
                $frontmatter = substr($content, 4, $end - 4);
                if (preg_match('/^description:\s*["\']?(.+?)["\']?\s*$/m', $frontmatter, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        return 'Detailed reference for ' . basename(dirname($skillFile));
    }
}
