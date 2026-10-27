<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin edits a week's title and description on the week page's Details tab; its number, position and whether it
 * is active do not change. Errors go to the `week` bag and the admin returns to the Details tab.
 */
#[ErrorBag('week')]
class UpdateTrainingWeekRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Browsers send textarea line breaks as "\r\n", so the description stores "\n" instead and an untouched save of a
     * multi-line description changes nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->merge($this->withUnixLineEndings(['description']));
    }

    /**
     * A failed save returns to the week page's Details tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#details';
    }

    /**
     * The given inputs that were sent, with each string's "\r\n" and lone "\r" line breaks turned into "\n".
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function withUnixLineEndings(array $fields): array
    {
        return collect($this->only($fields))
            ->map(fn (mixed $value): mixed => is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value)
            ->all();
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
