<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Models\ActivityRequirement;
use App\Models\SubmissionVersion;
use App\Support\Markdown;

class SubmissionAnswerPresenter
{
    public function fields(SubmissionVersion $version): array
    {
        $version->loadMissing('submission.activity.requirements', 'interactions');
        $activity = $version->submission->activity;
        $configured = $activity->requirements->where('requirement_type', 'submission_field')->sortBy('position')->mapWithKeys(fn (ActivityRequirement $requirement): array => [$requirement->configuration['key'] ?? '' => $requirement->configuration['label'] ?? $requirement->title]);
        $legacyLabels = ['feature_area' => 'Feature / Area', 'environment' => 'Environment', 'ai_tool' => 'AI Tool', 'artifact_type' => 'Artifact Type', 'exact_prompt' => 'Exact Prompt', 'exact_ai_output' => 'Exact AI Output', 'prompt' => 'Prompt', 'ai_output' => 'AI Output'];

        return collect($version->field_values ?? [])->except(['week_three_snapshot'])->map(fn (mixed $value, int|string $key): array => [
            'key' => $key,
            'label' => $configured[$key] ?? $legacyLabels[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'value' => $value,
            'display' => $this->displayValue($value),
            'markdown' => in_array($key, Markdown::OUTPUT_FIELDS, true),
        ])->filter(fn (array $field): bool => filled($field['value']))->values()->all();
    }

    public function displayValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? 'Not provided');
        }

        if (! is_array($value)) {
            return 'Not provided';
        }

        return collect($value)->map(function (mixed $item, int|string $key): string {
            $label = ucwords(str_replace(['_', '-'], ' ', (string) $key));
            $display = $this->displayValue($item);

            return $display === '' ? $label : $label.': '.$display;
        })->implode("\n");
    }

    public function structuredRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)->map(function (mixed $item, int|string $key): array {
            return [
                'label' => ucwords(str_replace(['_', '-'], ' ', (string) $key)),
                'value' => $this->displayValue($item),
            ];
        })->values()->all();
    }

    /** @return array<string,mixed> */
    public function weekThreeReview(mixed $snapshot, int $activityPosition, ?TrainingPath $trainingPath = null): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $findings = collect($snapshot['findings'] ?? [])->filter(fn (mixed $finding): bool => is_array($finding))->values();
        $allowedPaths = ($trainingPath ?? TrainingPath::Manual)->weekThreeTestPaths();
        $cleanFinding = fn (array $finding, int $index): array => [
            'number' => $index + 1,
            'title' => $this->text($finding['title'] ?? null),
            'fields' => $this->reviewFields($finding, [
                'severity' => 'Severity',
                'description' => 'Description',
                'steps_to_reproduce' => 'Steps to Reproduce',
                'expected_result' => 'Expected Result',
                'actual_result' => 'Actual Result',
                'affected_areas' => 'Affected Areas',
                'observed_evidence' => 'Observed Evidence',
                'ai_tool' => 'AI Tool Used',
                'ai_proposed_root_cause' => 'AI-Proposed Root Cause',
                'human_validation' => 'Human Validation',
                'human_validation_explanation' => 'Human Validation Explanation',
                'blast_radius' => 'Blast Radius',
                'next_step' => 'Next Step',
            ]),
            'tests' => collect($finding['tests'] ?? [])->filter(fn (mixed $test): bool => is_array($test))->filter(fn (array $test): bool => is_string($test['path'] ?? null) && in_array(TrainingPath::tryFrom($test['path']), $allowedPaths, true))->values()->map(fn (array $test): array => [
                'path' => $test['path'] ?? null,
                'path_label' => TrainingPath::from($test['path'])->label(),
                'title' => $this->text($test['name'] ?? null),
                'fields' => $this->reviewFields($test, [
                    'preconditions' => 'Preconditions',
                    'steps' => 'Steps',
                    'expected_result' => 'Expected Result',
                    'actual_result' => 'Actual Result',
                    'execution_result' => 'Execution Result',
                    'framework' => 'Framework',
                    'script_location' => 'Automation Details',
                    'artifact_evidence' => 'Automation Evidence',
                    'execution_evidence' => 'Execution Evidence',
                ]),
                'fix' => is_array($test['fix_validation'] ?? null) ? [
                    'fields' => $this->reviewFields($test['fix_validation'], [
                        'status' => 'Validation Status',
                        'buggy_result' => 'Original Issue / Buggy Result',
                        'bug_free_result' => 'Validation Result',
                        'evidence' => 'Validation Evidence',
                        'regression_issues' => 'Regression Impact',
                        'coverage_gaps' => 'Coverage / Gaps',
                    ]),
                ] : null,
            ])->all(),
        ];

        $items = $findings->map($cleanFinding)->all();
        if ($activityPosition === 1) {
            return ['kind' => 'rca', 'findings' => $items, 'summary' => $this->findingSummary($items)];
        }
        if ($activityPosition === 2) {
            return ['kind' => 'testing', 'findings' => $items];
        }

        return [
            'kind' => 'validation',
            'findings' => $items,
            'regression' => $this->reviewFields($snapshot, [
                'regression_areas' => 'Regression Areas',
                'regression_issues' => 'Regression Issues',
                'coverage_gaps' => 'Coverage / Gaps',
                'final_conclusion' => 'Conclusion',
            ]),
        ];
    }

    /** @return array<string,string> */
    private function reviewFields(array $values, array $definitions): array
    {
        return collect($definitions)->mapWithKeys(function (string $label, string $key) use ($values): array {
            return filled($values[$key] ?? null) ? [$label => $this->text($values[$key])] : [];
        })->all();
    }

    private function text(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }

        return collect($value)->map(fn (mixed $item, int|string $key): string => is_scalar($item) ? (string) $item : ((string) $key.': '.$this->text($item)))->implode("\n");
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function findingSummary(array $findings): array
    {
        $severities = collect($findings)->map(fn (array $finding): ?string => $finding['fields']['Severity'] ?? null)->filter();

        return ['total' => count($findings), 'severities' => $severities->countBy()->all()];
    }
}
