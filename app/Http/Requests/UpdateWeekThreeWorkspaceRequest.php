<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWeekThreeWorkspaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        abort_unless($this->user()?->role === 'participant', 403);

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
            'regression_areas' => ['nullable', 'string', 'max:10000'],
            'regression_issues' => ['nullable', 'string', 'max:10000'],
            'coverage_gaps' => ['nullable', 'string', 'max:10000'],
            'final_conclusion' => ['nullable', 'string', 'max:10000'],
            'findings' => ['nullable', 'array', 'max:5'],
            'findings.*' => ['array'],
            'findings.*.title' => ['nullable', 'string', 'max:255'],
            'findings.*.description' => ['nullable', 'string', 'max:10000'],
            'findings.*.steps_to_reproduce' => ['nullable', 'string', 'max:10000'],
            'findings.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.actual_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.observed_evidence' => ['nullable', 'string', 'max:10000'],
            'findings.*.ai_tool' => ['nullable', 'string', 'max:255'],
            'findings.*.ai_proposed_root_cause' => ['nullable', 'string', 'max:10000'],
            'findings.*.human_validation' => ['nullable', 'in:supported,partially_supported,unsupported'],
            'findings.*.human_validation_explanation' => ['nullable', 'string', 'max:10000'],
            'findings.*.severity' => ['nullable', 'string', 'max:100'],
            'findings.*.blast_radius' => ['nullable', 'string', 'max:10000'],
            'findings.*.affected_areas' => ['nullable', 'string', 'max:10000'],
            'findings.*.next_step' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests' => ['nullable', 'array'],
            'findings.*.tests.*' => ['array'],
            'findings.*.tests.*.name' => ['nullable', 'string', 'max:255'],
            'findings.*.tests.*.preconditions' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.steps' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.actual_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.execution_result' => ['nullable', 'string', 'max:255'],
            'findings.*.tests.*.framework' => ['nullable', 'string', 'max:255'],
            'findings.*.tests.*.script_location' => ['nullable', 'string', 'max:1000'],
            'findings.*.tests.*.artifact_evidence' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.execution_evidence' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.fix_validation' => ['nullable', 'array'],
            'findings.*.tests.*.fix_validation.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
