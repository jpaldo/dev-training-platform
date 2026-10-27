<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin adds a submission field to an activity from the activity page's Submission fields tab, with a label,
 * optional help, one of the offered types and whether it is required. Its key and position are set by
 * `ActivityFieldService`. Errors go to the `addField` bag and the admin returns to the Submission fields tab.
 */
#[ErrorBag('addField')]
class StoreActivityFieldRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * A label, optional help, one of the offered types (`ActivityRequirement::FIELD_TYPES`) and whether the field is
     * required.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', Rule::in(array_keys(ActivityRequirement::FIELD_TYPES))],
            'required' => ['boolean'],
        ];
    }

    /**
     * The validated settings of the new field. Help left out is null and "required" left out is false.
     *
     * @return array{label: string, help: ?string, type: string, required: bool}
     */
    public function settings(): array
    {
        return [
            'label' => $this->validated('label'),
            'help' => $this->validated('help'),
            'type' => $this->validated('type'),
            'required' => $this->boolean('required'),
        ];
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the help stores "\n" instead.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['help']);
    }

    /**
     * A failed add returns to the activity page's Submission fields tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.activities.edit', [$this->program(), $this->week(), $this->activity()]).'#fields';
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
