<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesLineEndings;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin edits an activity's title, description, objectives and instructions on the activity page's Details tab;
 * its position, whether it is active and its `configuration` (scoring dimensions, expected files) do not change here.
 * Errors go to the `activity` bag and the admin returns to the Details tab.
 */
#[ErrorBag('activity')]
class UpdateActivityRequest extends FormRequest
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
     * Browsers send textarea line breaks as "\r\n", so the multi-line inputs store "\n" instead and an untouched save
     * of multi-line text changes nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeLineEndings(['description', 'objectives', 'instructions']);
    }

    /**
     * A failed save returns to the activity page's Details tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.activities.edit', [$this->program(), $this->week(), $this->activity()]).'#details';
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
