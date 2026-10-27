<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin edits the title and description of a week's checklist (its first active Gate) on the week page's Checklist
 * tab. A week without a checklist is not found. Errors go to the `checklist` bag and the admin returns to the Checklist
 * tab.
 */
#[ErrorBag('checklist')]
class UpdateChecklistRequest extends FormRequest
{
    use NormalizesLineEndings;

    public function authorize(): bool
    {
        abort_if($this->checklist() === null, 404);

        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * A title and an optional description, both plain text.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The week's checklist: its first active Gate.
     */
    public function checklist(): ?TrainingGate
    {
        return $this->week()->checklist;
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
     * A failed save returns to the week page's Checklist tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#checklist';
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
