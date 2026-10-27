<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumScheduleService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An admin edits a week's title, description and dates on the week page's Details tab; its number, position and
 * whether it is active do not change. Errors go to the `week` bag and the admin returns to the Details tab.
 */
#[ErrorBag('week')]
class UpdateTrainingWeekRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * A title and an optional description, both plain text, and optional dates: both or neither, the end on or after
     * the start (the order against the curriculum's other weeks is checked in after()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['nullable', 'date', 'required_with:ends_on'],
            'ends_on' => ['nullable', 'date', 'required_with:starts_on', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_on' => 'start date',
            'ends_on' => 'end date',
        ];
    }

    /**
     * Once both dates are valid, the week must keep its place among the curriculum's other dated weeks: it starts
     * after the earlier weeks end and ends before the later weeks start
     * (CurriculumScheduleService::weekDateConflicts()).
     *
     * @return list<callable(Validator): void>
     */
    public function after(CurriculumScheduleService $schedule): array
    {
        return [function (Validator $validator) use ($schedule): void {
            if ($validator->errors()->hasAny(['starts_on', 'ends_on']) || $this->date('starts_on') === null || $this->date('ends_on') === null) {
                return;
            }

            foreach ($schedule->weekDateConflicts($this->week(), $this->date('starts_on'), $this->date('ends_on')) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the description stores "\n" instead and an untouched save of a
     * multi-line description changes nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description']);
    }

    /**
     * A failed save returns to the week page's Details tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#details';
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function week(): TrainingWeek
    {
        return $this->route('week');
    }
}
