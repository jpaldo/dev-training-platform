<?php

namespace App\Support;

final class FieldLimits
{
    public const TEXTAREA = 10000;

    public const TEXT = 1000;

    public const URL = 2048;

    public const NOTE = 5000;

    public const ARTIFACT_TYPE = 120;

    public const EXACT_PROMPT_OUTPUT = 50000;

    public const CHECK_NOTES = 2000;

    public const REVIEW_COMMENTS = 5000;

    public const FINDING = 3000;

    public const EVIDENCE = 3000;

    public const EVIDENCE_TEXT = 20000;

    public static function forType(string $type): int
    {
        return match ($type) {
            'text' => self::TEXT,
            'url', 'github_url' => self::URL,
            default => self::TEXTAREA,
        };
    }

    public static function forField(string $key, string $type = 'textarea'): int
    {
        return in_array($key, ['exact_prompt', 'exact_ai_output', 'ai_output', 'prompt'], true) ? self::EXACT_PROMPT_OUTPUT : self::forType($type);
    }

    public static function label(string $type): string
    {
        return number_format(self::forType($type));
    }
}
