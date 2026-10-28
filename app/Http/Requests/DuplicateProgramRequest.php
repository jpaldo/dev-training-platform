<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin duplicates a curriculum on its Duplicate page: the copy's name, which no other curriculum has, an optional
 * description, an optional first day of Week 1 and whether the copy is offered to participants (unchecked, it is
 * not). The name rule and its message are `StoreProgramRequest`'s. Errors return to the form.
 */
class DuplicateProgramRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Program::class) === true
            && $this->user()->can('view', $this->program());
    }

    /**
     * A unique name and an optional description, both plain text, an optional start date, and the "Offered to
     * participants" checkbox.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(Program::class, 'name')],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['nullable', 'date'],
            'offered' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_on' => 'start date',
            'offered' => 'offered to participants',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another program already has this name.',
        ];
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the description stores "\n" instead.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description']);
    }

    private function program(): Program
    {
        return $this->route('program');
    }
}
