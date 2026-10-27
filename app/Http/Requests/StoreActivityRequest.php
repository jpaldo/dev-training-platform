<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin adds an activity to a week from the week page's Activities tab, with a title and optional description,
 * objectives and instructions. Its position, rubric and submission fields are set by `CurriculumWeekService`. Errors go
 * to the `addActivity` bag and the admin returns to the Activities tab.
 */
#[ErrorBag('addActivity')]
class StoreActivityRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * A title and optional description, objectives and instructions, all plain text.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the multi-line inputs store "\n" instead.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description', 'objectives', 'instructions']);
    }

    /**
     * A failed add returns to the week page's Activities tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#activities';
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
