<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Services\ProgramResourceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin adds a link to the curriculum, for the whole curriculum or one of its weeks. Errors go to the `resources`
 * bag and the admin returns to the curriculum page's Resources tab.
 */
#[ErrorBag('resources')]
class StoreProgramResourceRequest extends FormRequest
{
    private ?int $highestWeekNumber = null;

    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->program()) === true;
    }

    /**
     * A title, an http(s) URL, and no week (the whole curriculum) or a week number up to the curriculum's highest
     * active week.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'week_number' => ['nullable', 'integer', 'between:1,'.$this->highestWeekNumber()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $highestWeekNumber = $this->highestWeekNumber();

        return [
            'url.url' => 'The link must be a full web address starting with http:// or https://.',
            'week_number.between' => $highestWeekNumber === 0
                ? 'This program has no active weeks yet.'
                : "This program's last week is Week {$highestWeekNumber}.",
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
     * A failed add returns to the curriculum page's Resources tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#resources';
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    /**
     * The highest week number a resource can name (ProgramResourceService::highestWeekNumber()), read once.
     */
    private function highestWeekNumber(): int
    {
        return $this->highestWeekNumber ??= app(ProgramResourceService::class)->highestWeekNumber($this->program());
    }
}
