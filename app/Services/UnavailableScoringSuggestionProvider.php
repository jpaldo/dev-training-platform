<?php

namespace App\Services;

use App\Contracts\ScoringSuggestionProvider;
use App\Models\SubmissionVersion;
use RuntimeException;

class UnavailableScoringSuggestionProvider implements ScoringSuggestionProvider
{
    public function suggest(SubmissionVersion $version, array $dimensions, array $answers): array
    {
        throw new RuntimeException('AI scoring provider is not configured. Manual scoring remains available.');
    }

    public function improve(string $text, ?string $dimension = null): string
    {
        throw new RuntimeException('AI scoring provider is not configured. Manual feedback editing remains available.');
    }
}
