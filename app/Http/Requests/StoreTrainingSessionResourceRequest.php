<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * An admin adds a link to the session, for the whole session or one of its weeks. Errors go to the `resources` bag and
 * the admin returns to the dashboard's Resources tab.
 */
#[ErrorBag('resources')]
class StoreTrainingSessionResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->trainingSession()) === true;
    }

    /**
     * A title, an http(s) URL, and no week (the whole session) or one of the session's weeks.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'week_number' => ['nullable', 'integer', 'between:1,'.$this->trainingSession()->weeks_count],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $weeksCount = $this->trainingSession()->weeks_count;

        return [
            'url.url' => 'The link must be a full web address starting with http:// or https://.',
            'week_number.between' => "This session has only {$weeksCount} ".Str::plural('week', $weeksCount).'.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'url' => 'link',
            'week_number' => 'week',
        ];
    }

    /**
     * A failed add returns to the dashboard's Resources tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.sessions.show', $this->trainingSession()).'#resources';
    }

    private function trainingSession(): TrainingSession
    {
        return $this->route('trainingSession');
    }
}
