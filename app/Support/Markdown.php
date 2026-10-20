<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

final class Markdown
{
    public const OUTPUT_FIELDS = ['exact_ai_output', 'ai_output', 'exact_prompt', 'generated_test', 'corrected_script'];

    public const EDITOR_PREVIEW_FIELDS = ['issue_caught', 'action_taken'];

    public static function editorPreview(string $key): bool
    {
        return in_array($key, self::OUTPUT_FIELDS, true) || in_array($key, self::EDITOR_PREVIEW_FIELDS, true);
    }

    public static function toHtml(?string $text): string
    {
        if (blank($text)) {
            return '';
        }

        $text = self::structuredPlainTextToMarkdown((string) $text);

        static $converter = null;

        $converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 64,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        return (string) $converter->convert((string) $text);
    }

    private static function structuredPlainTextToMarkdown(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $fieldMatches = 0;
        $inFence = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                $inFence = ! $inFence;

                continue;
            }
            if (! $inFence && self::fieldLine($line) !== null) {
                $fieldMatches++;
            }
        }

        if ($fieldMatches < 2 || self::containsMarkdownStructure($lines)) {
            return $text;
        }

        $output = [];
        $inFence = false;
        $hasContent = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                $inFence = ! $inFence;
                $output[] = $line;
                $hasContent = true;

                continue;
            }
            if ($inFence) {
                $output[] = $line;

                continue;
            }

            $field = self::fieldLine($line);
            if ($field !== null) {
                if ($hasContent && end($output) !== '') {
                    $output[] = '';
                }
                $output[] = '### '.strtoupper($field['label']);
                if ($field['value'] !== '') {
                    $output[] = $field['value'];
                    $hasContent = true;
                } else {
                    $output[] = '';
                    $hasContent = false;
                }

                continue;
            }

            $section = self::sectionHeading($line);
            if ($section !== null) {
                if ($hasContent && end($output) !== '') {
                    $output[] = '';
                }
                $output[] = '### '.strtoupper($section);
                $output[] = '';
                $hasContent = false;

                continue;
            }

            $output[] = $line;
            if (trim($line) !== '') {
                $hasContent = true;
            }
        }

        return implode("\n", $output);
    }

    /** @return array{label:string,value:string}|null */
    private static function fieldLine(string $line): ?array
    {
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 &(),.\/_?\-]{1,79}):(?:\s*(.*))?\s*$/', $line, $matches) !== 1) {
            return null;
        }
        $label = trim($matches[1]);
        if (self::looksLikeProse($label)) {
            return null;
        }

        return ['label' => $label, 'value' => trim($matches[2] ?? '')];
    }

    private static function sectionHeading(string $line): ?string
    {
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 &(),.\/_?\-]{1,79}):\s*$/', $line, $matches) !== 1) {
            return null;
        }
        $label = trim($matches[1]);

        return self::looksLikeProse($label) ? null : $label;
    }

    private static function looksLikeProse(string $label): bool
    {
        if (preg_match('/[.!?]$/', $label) === 1 || preg_match('/^(I|We|The|A|An|When|If|This|That)\b/i', $label) === 1) {
            return true;
        }

        return str_word_count($label) > 8;
    }

    /** @param array<int, string> $lines */
    private static function containsMarkdownStructure(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                return true;
            }
            if (preg_match('/^\s{0,3}#{1,6}\s+/', $line) || preg_match('/^\s*>\s+/', $line)) {
                return true;
            }
            if (preg_match('/^\s*\|.*\|\s*$/', $line)) {
                return true;
            }
            if (preg_match('/(^|\s)(\*\*|__)[^\n]+(\*\*|__)(\s|$)/', $line)) {
                return true;
            }
        }

        return false;
    }
}
