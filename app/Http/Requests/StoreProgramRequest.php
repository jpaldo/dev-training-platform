<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Program;
use App\Services\CurriculumService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin creates a curriculum on the New curriculum page: a name no other curriculum has, an optional description,
 * its number of weeks and an optional first day of Week 1. Errors return to the form.
 */
class StoreProgramRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Program::class) === true;
    }

    /**
     * A unique name and an optional description, both plain text, 1 to `CurriculumService::MAX_WEEKS` weeks and an
     * optional start date.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(Program::class, 'name')],
            'description' => ['nullable', 'string', 'max:5000'],
            'weeks_count' => ['required', 'integer', 'between:1,'.CurriculumService::MAX_WEEKS],
            'starts_on' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'weeks_count' => 'number of weeks',
            'starts_on' => 'start date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another curriculum already has this name.',
        ];
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the description stores "\n" instead.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description']);
    }
}
