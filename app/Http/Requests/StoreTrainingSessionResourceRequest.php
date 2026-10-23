<?php

namespace App\Http\Requests;

use App\Enums\TrainingSessionResourceType;
use App\Models\TrainingSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * An admin adds a file or a link to the session, for the whole session or one of its weeks. Errors go to the
 * `resources` bag and the admin returns to the dashboard's Resources tab.
 */
#[ErrorBag('resources')]
class StoreTrainingSessionResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->trainingSession()) === true;
    }

    /**
     * A title and a type; a file of an allowed type up to `training.session_resource_max_kb` for files, an http(s) URL
     * for links; and no week (the whole session) or one of the session's weeks. The field of the other type is ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(TrainingSessionResourceType::class)],
            'file' => [
                'exclude_unless:type,'.TrainingSessionResourceType::File->value,
                'required',
                'file',
                'max:'.(int) config('training.session_resource_max_kb'),
                'extensions:'.implode(',', config('training.session_resource_extensions')),
                'mimetypes:'.implode(',', config('training.session_resource_mimes')),
            ],
            'url' => [
                'exclude_unless:type,'.TrainingSessionResourceType::Link->value,
                'required',
                'string',
                'max:2048',
                'url:http,https',
            ],
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
            'file.max' => self::maxFileSizeMessage(),
            'file.extensions' => 'The file must be a PDF, Word, Excel or PowerPoint document, an image or an MP4 video.',
            'file.mimetypes' => 'The file must be a PDF, Word, Excel or PowerPoint document, an image or an MP4 video.',
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
     * The message for a file over `training.session_resource_max_kb`, e.g. "The file must not exceed 100 MB." (also
     * shown when PHP rejects the upload before validation; see bootstrap/app.php).
     */
    public static function maxFileSizeMessage(): string
    {
        return 'The file must not exceed '.round((int) config('training.session_resource_max_kb') / 1024, 1).' MB.';
    }

    /**
     * The resource type chosen.
     */
    public function resourceType(): TrainingSessionResourceType
    {
        return $this->enum('type', TrainingSessionResourceType::class);
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
