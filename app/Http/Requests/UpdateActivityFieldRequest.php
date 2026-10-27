<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin edits a submission field's label, help, type and whether it is required on the activity page's Submission
 * fields tab; its key never changes. A requirement that is not a submission field, or a field that was removed, is not
 * found. Each field's errors go to its own bag (see errorBagFor()), so the tab shows them beside the right form, and the
 * admin returns to that tab.
 */
class UpdateActivityFieldRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        abort_unless($this->field()->isSubmissionField() && ! $this->field()->isRemovedField(), 404);

        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * A label, optional help, one of the offered types (a field whose stored type is not offered, such as `select`, may
     * also keep it) and whether the field is required.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', Rule::in($this->allowedTypes())],
            'required' => ['boolean'],
        ];
    }

    /**
     * The validated settings, with "required" as a boolean when it was sent. Help or "required" left out of the request
     * is not included.
     *
     * @return array{label: string, help?: ?string, type: string, required?: bool}
     */
    public function settings(): array
    {
        $settings = $this->safe()->only(['label', 'help', 'type']);

        if ($this->has('required')) {
            $settings['required'] = $this->boolean('required');
        }

        return $settings;
    }

    /**
     * The error bag of a submission field's form, e.g. "field12".
     */
    public static function errorBagFor(ActivityRequirement $field): string
    {
        return 'field'.$field->id;
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the help stores "\n" instead and an untouched save of multi-line
     * help changes nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['help']);
    }

    /**
     * Put the errors in the field's own bag before the request redirects back.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->errorBag = self::errorBagFor($this->field());

        parent::failedValidation($validator);
    }

    /**
     * A failed save returns to the activity page's Submission fields tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.activities.edit', [$this->program(), $this->week(), $this->activity()]).'#fields';
    }

    /**
     * The types the field may take: the offered FIELD_TYPES, plus the field's own stored type when it is not offered
     * (such as `select` or `date`), which then cannot change.
     *
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        $types = array_keys(ActivityRequirement::FIELD_TYPES);

        if ($this->field()->hasFixedFieldType()) {
            $types[] = $this->field()->fieldType();
        }

        return $types;
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

    private function field(): ActivityRequirement
    {
        return $this->route('requirement');
    }
}
