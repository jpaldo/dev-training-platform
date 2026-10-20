<?php

namespace App\Http\Requests;

use App\Services\EvidenceFilePolicy;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreValidationArtifactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'trainee_name' => ['required', 'string', 'max:100'],
            'feature_area' => ['nullable', 'string', 'max:160'],
            'other_feature_area' => ['nullable', 'string', 'max:160'],
            'artifact_type' => ['nullable', 'string', 'max:120'],
            'other_artifact_type' => ['nullable', 'string', 'max:120'],
            'ai_tool' => ['nullable', 'string', 'max:120'],
            'prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'artifact_name' => ['nullable', 'string', 'max:160'],
            'artifact_content' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'evidence_files' => ['nullable', 'array', 'max:10'],
            'evidence_files.*' => app(EvidenceFilePolicy::class)->generalRules(),
        ];
    }

    /**
     * Apply the full artifact intake rules once the base rules pass, unless
     * the request is the legacy name-only start of the Lab.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->isLegacyArtifact()) {
                return;
            }

            $artifactValidator = validator($this->validationData(), $this->artifactRules());
            if ($artifactValidator->fails()) {
                $validator->errors()->merge($artifactValidator->errors());
            }
        }];
    }

    /**
     * Whether the request only names the trainee and uses the default Lab artifact.
     */
    public function isLegacyArtifact(): bool
    {
        return blank($this->input('feature_area')) && blank($this->input('artifact_content'));
    }

    /**
     * Rules for a participant-supplied artifact.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function artifactRules(): array
    {
        return [
            'feature_area' => ['required', 'string', Rule::in(config('validation_lab.feature_areas'))],
            'other_feature_area' => ['required_if:feature_area,Other', 'nullable', 'string', 'max:160'],
            'artifact_type' => ['required', 'string', Rule::in(config('validation_lab.artifact_types'))],
            'other_artifact_type' => ['required_if:artifact_type,Other', 'nullable', 'string', 'max:120'],
            'ai_tool' => ['required', 'string', 'max:120'],
            'artifact_name' => ['required', 'string', 'max:160'],
            'artifact_content' => ['required', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
        ];
    }
}
