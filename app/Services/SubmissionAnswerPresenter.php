<?php

namespace App\Services;

use App\Models\ActivityRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Support\Markdown;
use Illuminate\Support\Collection;

class SubmissionAnswerPresenter
{
    public function fields(SubmissionVersion $version): array
    {
        $version->loadMissing('submission.activity.requirements', 'interactions');
        $activity = $version->submission->activity;
        $configured = $activity->requirements->where('requirement_type', 'submission_field')->sortBy('position')->mapWithKeys(fn (ActivityRequirement $requirement): array => [$requirement->configuration['key'] ?? '' => $requirement->configuration['label'] ?? $requirement->title]);
        $legacyLabels = ['feature_area' => 'Feature / Area', 'environment' => 'Environment', 'ai_tool' => 'AI Tool', 'artifact_type' => 'Artifact Type', 'exact_prompt' => 'Exact Prompt', 'exact_ai_output' => 'Exact AI Output', 'prompt' => 'Prompt', 'ai_output' => 'AI Output'];

        return collect($version->field_values ?? [])->map(fn (mixed $value, int|string $key): array => [
            'key' => $key,
            'label' => $configured[$key] ?? $legacyLabels[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'value' => $value,
            'display' => $this->displayValue($value),
            'markdown' => in_array($key, Markdown::OUTPUT_FIELDS, true),
        ])->filter(fn (array $field): bool => filled($field['value']))->values()->all();
    }

    /**
     * The evidence items with whether each is an uploaded file and the type label to show ("Uploaded File" for a
     * file, otherwise the evidence type).
     *
     * @param  Collection<int, SubmissionEvidence>  $evidence
     * @return Collection<int, array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>
     */
    public function evidenceItems(Collection $evidence): Collection
    {
        return $evidence->map(fn (SubmissionEvidence $item): array => [
            'evidence' => $item,
            'is_file' => $item->isUploadedFile(),
            'type_label' => $item->isUploadedFile() ? 'Uploaded File' : ucfirst($item->evidence_type),
        ])->values()->toBase();
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
}
