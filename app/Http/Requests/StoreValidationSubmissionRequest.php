<?php

namespace App\Http\Requests;

use App\Enums\ValidationCheckStatus;
use App\Enums\ValidationDecision;
use App\Services\EvidenceFilePolicy;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreValidationSubmissionRequest extends FormRequest
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
            'artifact_code' => ['required', 'string'],
            'artifact_name' => ['required', 'string', 'max:160'],
            'feature_area' => ['nullable', 'string', 'max:160'],
            'artifact_type' => ['nullable', 'string', 'max:120'],
            'ai_tool' => ['nullable', 'string', 'max:120'],
            'prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'artifact_content' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'evidence_files' => ['nullable', 'array', 'max:10'],
            'evidence_files.*' => app(EvidenceFilePolicy::class)->generalRules(),
            'source_status' => ['required', Rule::enum(ValidationCheckStatus::class)],
            'source_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'scope_status' => ['required', Rule::enum(ValidationCheckStatus::class)],
            'scope_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'spec_status' => ['required', Rule::enum(ValidationCheckStatus::class)],
            'spec_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'cross_check_status' => ['required', Rule::enum(ValidationCheckStatus::class)],
            'cross_check_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'run_status' => ['required', Rule::enum(ValidationCheckStatus::class)],
            'run_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'log_status' => ['required', Rule::enum(ValidationCheckStatus::class)],
            'log_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'finding' => ['required', 'string', 'max:'.FieldLimits::FINDING],
            'decision' => ['required', Rule::enum(ValidationDecision::class)],
            'evidence_notes' => ['required', 'string', 'max:'.FieldLimits::EVIDENCE],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['artifact_name' => is_string($this->input('artifact_name')) ? trim($this->input('artifact_name')) : $this->input('artifact_name')]);
    }
}
