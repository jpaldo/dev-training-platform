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
