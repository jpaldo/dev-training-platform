<?php

namespace App\Contracts;

use App\Models\SubmissionVersion;

interface ScoringSuggestionProvider
{
    /** @return array{dimensions: array<string, array{suggested_score:int, feedback:string, evidence_basis?:string}>, overall_feedback?:string, provider?:string, model?:string} */
    public function suggest(SubmissionVersion $version, array $dimensions, array $answers): array;
}
