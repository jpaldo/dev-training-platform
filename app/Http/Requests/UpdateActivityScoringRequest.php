<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An admin replaces an activity's scoring dimensions on the activity page's Scoring tab: up to nine rows of a label and
 * a maximum, in rubric order, whose maxima add up to 100. Blank rows are ignored. Once a score exists for any version
 * of the activity the dimensions can no longer change. Errors go to the `scoring` bag and the admin returns to the
 * Scoring tab.
 */
#[ErrorBag('scoring')]
class UpdateActivityScoringRequest extends FormRequest
{
    /** Why the dimensions of a scored activity are read-only, shown on the Scoring tab and as the save's error. */
    public const LOCKED_MESSAGE = 'Scored submissions exist, so the dimensions can no longer change.';

    /** The longest dimension label. */
    public const LABEL_MAX_LENGTH = 60;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * One to nine rows, each with a label of up to 60 characters and a whole-number maximum from 1 to 100.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dimensions' => ['required', 'array', 'max:'.Activity::MAX_SCORING_DIMENSIONS],
            'dimensions.*' => ['array:label,max'],
            'dimensions.*.label' => ['required', 'string', 'max:'.self::LABEL_MAX_LENGTH],
            'dimensions.*.max' => ['required', 'integer', 'between:1,'.Activity::SCORING_TOTAL],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dimensions.required' => 'Add at least one dimension.',
            'dimensions.max' => 'An activity has at most :max dimensions.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dimensions.*.label' => 'label',
            'dimensions.*.max' => 'maximum',
        ];
    }

    /**
     * Reject the save once the activity has a score, and otherwise, when every row is valid, unless the maxima add up
     * to 100.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->activity()->hasScores()) {
                $validator->errors()->add('dimensions', self::LOCKED_MESSAGE);

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $total = array_sum(array_column($this->dimensions(), 'max'));

            if ($total !== Activity::SCORING_TOTAL) {
                $validator->errors()->add('dimensions', "The maxima add up to {$total}; they must add up to ".Activity::SCORING_TOTAL.'.');
            }
        }];
    }

    /**
     * The rows that are not blank, in the order they were sent, each with its label and maximum.
     *
     * @return list<array{label: string, max: int}>
     */
    public function dimensions(): array
    {
        return collect($this->input('dimensions'))
            ->map(fn (array $row): array => ['label' => (string) $row['label'], 'max' => (int) $row['max']])
            ->values()
            ->all();
    }

    /**
     * Drop the rows whose label and maximum are both blank, keeping the others under their row numbers so each error
     * shows beside its row.
     */
    protected function prepareForValidation(): void
    {
        $dimensions = $this->input('dimensions');

        if (! is_array($dimensions)) {
            return;
        }

        $this->merge([
            'dimensions' => array_filter($dimensions, fn (mixed $row): bool => ! $this->isBlankRow($row)),
        ]);
    }

    /**
     * A failed save returns to the activity page's Scoring tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.activities.edit', [$this->program(), $this->week(), $this->activity()]).'#scoring';
    }

    /**
     * Whether the row has neither a label nor a maximum.
     */
    private function isBlankRow(mixed $row): bool
    {
        return is_array($row) && blank($row['label'] ?? null) && blank($row['max'] ?? null);
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function week(): TrainingWeek
    {
        return $this->route('week');
    }

    private function activity(): Activity
    {
        return $this->route('activity');
    }
}
