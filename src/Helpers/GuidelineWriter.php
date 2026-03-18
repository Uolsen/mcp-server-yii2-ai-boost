<?php

declare(strict_types=1);

namespace codechap\yii2boost\Helpers;

/**
 * Handles writing guidelines to CLAUDE.md with safe regeneration.
 *
 * Guidelines are wrapped in XML tags so they can be replaced on update
 * without destroying user content outside the tags.
 */
final class GuidelineWriter
{
    private const OPEN_TAG = '<yii2-boost-guidelines>';
    private const CLOSE_TAG = '</yii2-boost-guidelines>';

    /**
     * Write guidelines to a file (CLAUDE.md, AGENTS.md, etc.)
     *
     * If the file contains existing yii2-boost-guidelines tags, only the content
     * between the tags is replaced. User content outside tags is preserved.
     * If the file exists without tags, the guidelines block is appended.
     * If the file doesn't exist, it is created.
     *
     * @param string $filePath Path to the target file (e.g., CLAUDE.md)
     * @param string $guidelineContent The guideline content to embed
     * @param array<array{name: string, description: string}> $skills Skills for activation section
     * @return bool Whether the file was modified
     */
    public static function write(string $filePath, string $guidelineContent, array $skills = []): bool
    {
        $block = self::buildBlock($guidelineContent, $skills);

        if (!file_exists($filePath)) {
            file_put_contents($filePath, $block);
            return true;
        }

        $existing = file_get_contents($filePath);
        if ($existing === false) {
            file_put_contents($filePath, $block);
            return true;
        }

        // Check if file already contains our tags
        if (strpos($existing, self::OPEN_TAG) !== false && strpos($existing, self::CLOSE_TAG) !== false) {
            $pattern = '/' . preg_quote(self::OPEN_TAG, '/') . '.*?' . preg_quote(self::CLOSE_TAG, '/') . '/s';
            $newContent = preg_replace($pattern, $block, $existing, 1);

            if ($newContent === $existing) {
                return false; // No change
            }

            file_put_contents($filePath, $newContent);
            return true;
        }

        // Tags not found — append to end of file
        $separator = '';
        if (!empty($existing) && substr($existing, -1) !== "\n") {
            $separator = "\n";
        }
        file_put_contents($filePath, $existing . $separator . "\n" . $block);
        return true;
    }

    /**
     * Check if a file contains yii2-boost-guidelines tags.
     *
     * @param string $filePath Path to the file
     * @return bool
     */
    public static function hasGuidelines(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }
        return strpos($content, self::OPEN_TAG) !== false
            && strpos($content, self::CLOSE_TAG) !== false;
    }

    /**
     * Discover skills from a directory of SKILL.md files.
     *
     * Reads YAML frontmatter from each SKILL.md to extract name and description.
     *
     * @param string $skillsPath Path to skills directory (e.g., .ai/skills/)
     * @return array<array{name: string, description: string}>
     */
    public static function discoverSkills(string $skillsPath): array
    {
        if (!is_dir($skillsPath)) {
            return [];
        }

        $skillFiles = glob($skillsPath . '/*/SKILL.md');
        if (empty($skillFiles)) {
            return [];
        }

        $skills = [];
        sort($skillFiles);

        foreach ($skillFiles as $skillFile) {
            $content = file_get_contents($skillFile);
            if ($content === false) {
                continue;
            }

            $name = basename(dirname($skillFile));
            $description = '';

            // Parse YAML frontmatter
            if (strpos($content, "---\n") === 0) {
                $end = strpos($content, "\n---\n", 4);
                if ($end !== false) {
                    $frontmatter = substr($content, 4, $end - 4);
                    if (preg_match('/^name:\s*["\']?(.+?)["\']?\s*$/m', $frontmatter, $nameMatch)) {
                        $name = trim($nameMatch[1]);
                    }
                    if (preg_match('/^description:\s*["\']?(.+?)["\']?\s*$/m', $frontmatter, $descMatch)) {
                        $description = trim($descMatch[1]);
                    }
                }
            }

            $skills[] = [
                'name' => $name,
                'description' => $description,
            ];
        }

        return $skills;
    }

    /**
     * Build the full guidelines block with XML tags.
     *
     * @param string $content The guideline content
     * @param array<array{name: string, description: string}> $skills Skills for activation section
     * @return string
     */
    private static function buildBlock(string $content, array $skills = []): string
    {
        $parts = [self::OPEN_TAG, ''];

        // Guidelines section
        $parts[] = '=== guidelines ===';
        $parts[] = '';
        $parts[] = trim($content);

        // Skills activation section
        if (!empty($skills)) {
            $parts[] = '';
            $parts[] = '=== skills activation ===';
            $parts[] = '';
            $parts[] = '## Skills Activation';
            $parts[] = '';
            $parts[] = 'This project has domain-specific skills available. '
                . 'You MUST activate the relevant skill whenever you work in that domain'
                . "—don't wait until you're stuck.";
            $parts[] = '';
            foreach ($skills as $skill) {
                $parts[] = '- `' . $skill['name'] . '`'
                    . ($skill['description'] !== '' ? ' — ' . $skill['description'] : '');
            }
        }

        $parts[] = '';
        $parts[] = self::CLOSE_TAG;

        return implode("\n", $parts) . "\n";
    }
}
