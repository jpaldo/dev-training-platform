<?php

namespace App\Contracts;

use App\Models\SubmissionVersion;

interface ScoringSuggestionProvider
{
    /**
     * Suggest a score for the version's activity: its program, week, title and description, and its scoring dimensions.
     *
     * @param  array<string, array{key: string, label: string, max: int}>  $dimensions  the activity's scoring dimensions keyed by dimension key, in rubric order
     * @param  list<array<string, mixed>>  $answers
     * @return array{dimensions: array<string, array{suggested_score:int, feedback:string, evidence_basis?:string}>, overall_feedback?:string, provider?:string, model?:string}
     */
    public function suggest(SubmissionVersion $version, array $dimensions, array $answers): array;
}
