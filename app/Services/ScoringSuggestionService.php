<?php

namespace App\Services;

use App\Contracts\ScoringSuggestionProvider;
use App\Models\ActivityScoreSuggestion;
use App\Models\SubmissionVersion;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ScoringSuggestionService
{
    public function __construct(private readonly ScoringSuggestionProvider $provider, private readonly SubmissionAnswerPresenter $answers) {}

    public function improve(string $text, ?string $dimension = null): string
    {
        if (! method_exists($this->provider, 'improve')) {
            throw new RuntimeException('AI feedback improvement is not configured. Manual editing remains available.');
        }

        return $this->provider->improve($text, $dimension);
    }

    public function generate(SubmissionVersion $version): ActivityScoreSuggestion
    {
        $version->loadMissing(['submission.activity.week.program', 'interactions']);
        $dimensions = collect($version->submission->activity->scoringDimensions())->keyBy('key')->all();
        if ($dimensions === []) {
            throw new RuntimeException(ScoringService::NO_DIMENSIONS_MESSAGE);
        }
        $raw = $this->provider->suggest($version, $dimensions, $this->answers->fields($version));
        $validated = Validator::make($raw, ['dimensions' => ['required', 'array'], 'dimensions.*.suggested_score' => ['required', 'integer'], 'dimensions.*.feedback' => ['required', 'string', 'max:10000'], 'dimensions.*.evidence_basis' => ['nullable', 'string', 'max:10000'], 'overall_feedback' => ['nullable', 'string', 'max:10000'], 'evidence_processing' => ['nullable', 'array', 'max:100']])->validate();
        foreach ($validated['dimensions'] as $key => $dimension) {
            abort_unless(isset($dimensions[$key]), 422, 'Unknown scoring dimension.');
            abort_unless($dimension['suggested_score'] >= 0 && $dimension['suggested_score'] <= $dimensions[$key]['max'], 422, 'Suggested score is outside the dimension range.');
        }
        abort_unless(collect(array_keys($dimensions))->diff(array_keys($validated['dimensions']))->isEmpty(), 422, 'The suggested response did not cover every scoring dimension.');

        return ActivityScoreSuggestion::query()->updateOrCreate(['submission_version_id' => $version->id], ['dimensions' => $validated['dimensions'], 'overall_feedback' => $validated['overall_feedback'] ?? null, 'evidence_processing' => $validated['evidence_processing'] ?? null, 'provider' => $raw['provider'] ?? get_class($this->provider), 'model' => $raw['model'] ?? null, 'generated_at' => now()]);
    }
}
