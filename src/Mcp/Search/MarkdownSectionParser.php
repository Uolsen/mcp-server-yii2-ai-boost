<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Search;

/**
 * Parses markdown files into sections split on H2 headings.
 *
 * Each section contains the H2 title and the body text below it,
 * up to the next H2 heading or end of file. Content before the
 * first H2 is grouped under the document title (H1).
 */
class MarkdownSectionParser
{
    /**
     * Parse a markdown string into sections.
     *
     * @param string $markdown Raw markdown content
     * @param string $filePath Original file path (for metadata)
     * @return array{file_title: string, sections: array<int, array{section_title: string, body: string}>}
     */
    public function parse(string $markdown, string $filePath = ''): array
    {
        $lines = explode("\n", $markdown);
        $fileTitle = basename($filePath, '.md');
        $sections = [];
        $currentSection = null;
        $bodyLines = [];

        foreach ($lines as $line) {
            // Detect H1 heading (file title)
            if (preg_match('/^#\s+(.+)$/', $line, $matches)) {
                $fileTitle = trim($matches[1]);
                // If we already have accumulated body lines before H1, discard them
                // H1 is the document title, not a section
                continue;
            }

            // Detect H2 heading (section boundary)
            if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
                // Save previous section
                if ($currentSection !== null) {
                    $sections[] = [
                        'section_title' => $currentSection,
                        'body' => $this->trimBody($bodyLines),
                    ];
                } elseif (!empty($bodyLines)) {
                    // Content before first H2 — group as "Introduction"
                    $body = $this->trimBody($bodyLines);
                    if ($body !== '') {
                        $sections[] = [
                            'section_title' => 'Introduction',
                            'body' => $body,
                        ];
                    }
                }

                $currentSection = trim($matches[1]);
                $bodyLines = [];
                continue;
            }

            $bodyLines[] = $line;
        }

        // Save last section
        if ($currentSection !== null) {
            $sections[] = [
                'section_title' => $currentSection,
                'body' => $this->trimBody($bodyLines),
            ];
        } elseif (!empty($bodyLines)) {
            // File has no H2 headings — treat entire body as one section
            $body = $this->trimBody($bodyLines);
            if ($body !== '') {
                $sections[] = [
                    'section_title' => 'Content',
                    'body' => $body,
                ];
            }
        }

        return [
            'file_title' => $fileTitle,
            'sections' => $sections,
        ];
    }

    /**
     * Trim leading/trailing blank lines from body content.
     *
     * @param array $lines Body lines
     * @return string Trimmed body text
     */
    private function trimBody(array $lines): string
    {
        // Remove leading empty lines
        while (!empty($lines) && trim($lines[0]) === '') {
            array_shift($lines);
        }

        // Remove trailing empty lines
        while (!empty($lines) && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }
}
