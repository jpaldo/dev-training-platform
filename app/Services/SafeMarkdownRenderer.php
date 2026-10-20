<?php

namespace App\Services;

use Illuminate\Support\HtmlString;

class SafeMarkdownRenderer
{
    public function render(?string $markdown): HtmlString
    {
        $text = trim((string) $markdown);
        if ($text === '') {
            return new HtmlString('');
        }

        $lines = preg_split('/\r\n|\r|\n/', e($text));
        $html = [];
        $inList = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*###\s+(.+)$/', $line, $match)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<h4>'.e($match[1]).'</h4>';
            } elseif (preg_match('/^\s*##\s+(.+)$/', $line, $match)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<h3>'.e($match[1]).'</h3>';
            } elseif (preg_match('/^\s*#\s+(.+)$/', $line, $match)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<h2>'.e($match[1]).'</h2>';
            } elseif (preg_match('/^\s*[-*]\s+(.+)$/', $line, $match)) {
                if (! $inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>'.$this->inline($match[1]).'</li>';
            } elseif (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $match)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<ol><li>'.$this->inline($match[1]).'</li></ol>';
            } elseif (trim($line) === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
            } else {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $html[] = '<p>'.$this->inline($line).'</p>';
            }
        }
        if ($inList) {
            $html[] = '</ul>';
        }

        return new HtmlString(implode('', $html));
    }

    private function inline(string $line): string
    {
        $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);

        return preg_replace('/\*(.+?)\*/', '<em>$1</em>', $line);
    }
}
