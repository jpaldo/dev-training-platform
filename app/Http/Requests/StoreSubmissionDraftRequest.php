<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Support\FieldLimits;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionDraftRequest extends FormRequest
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $fieldConfigurations = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Participant;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'field_values' => ['nullable', 'array'],
            'field_values.*' => ['nullable', 'string', 'max:'.FieldLimits::TEXTAREA],
            'participant_note' => ['nullable', 'string', 'max:'.FieldLimits::NOTE],
            'interactions' => ['nullable', 'array', 'max:20'],
            'interactions.*' => ['array'],
            'interactions.*.prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'interactions.*.output' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
        ];

        foreach ($this->fieldConfigurations() as $key => $configuration) {
            $rules['field_values.'.$key] = ['nullable', 'string', 'max:'.FieldLimits::forField($key, $configuration['type'] ?? 'textarea')];
        }

        return $rules;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->fieldConfigurations() as $key => $configuration) {
            $label = $configuration['label'] ?? ucwords(str_replace('_', ' ', $key));
            $messages['field_values.'.$key.'.max'] = $label.' must be '.number_format(FieldLimits::forField($key, $configuration['type'] ?? 'textarea')).' characters or fewer.';
        }

        $messages['participant_note.max'] = 'The participant note must be '.number_format(FieldLimits::NOTE).' characters or fewer.';

        return $messages;
    }

    /**
     * Resolve the configured submission fields for the requested activity.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldConfigurations(): array
    {
        if ($this->fieldConfigurations !== null) {
            return $this->fieldConfigurations;
        }

        $this->fieldConfigurations = [];
        $activity = $this->route('activity');
        if (! $activity instanceof Activity) {
            return $this->fieldConfigurations;
        }

        foreach ($activity->requirements()->where('requirement_type', 'submission_field')->get() as $requirement) {
            $configuration = $requirement->configuration;
            if (($configuration['deprecated'] ?? false) || ! isset($configuration['key'])) {
                continue;
            }
            $this->fieldConfigurations[$configuration['key']] = $configuration;
        }

        return $this->fieldConfigurations;
    }
}
