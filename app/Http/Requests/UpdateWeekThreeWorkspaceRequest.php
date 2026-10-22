<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWeekThreeWorkspaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        abort_unless($this->user()?->role === UserRole::Participant, 403);

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

    /**
     * Get the readable names of the workspace fields, numbering each finding by its position.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'regression_areas' => 'areas tested',
            'regression_issues' => 'regression issues discovered',
            'coverage_gaps' => 'coverage gaps',
            'final_conclusion' => 'final conclusion',
            'findings' => 'findings',
            'findings.*' => 'Finding :position',
            'findings.*.title' => 'Finding :position issue title',
            'findings.*.description' => 'Finding :position description',
            'findings.*.steps_to_reproduce' => 'Finding :position steps to reproduce',
            'findings.*.expected_result' => 'Finding :position expected result',
            'findings.*.actual_result' => 'Finding :position actual result',
            'findings.*.observed_evidence' => 'Finding :position observed behavior / evidence',
            'findings.*.ai_tool' => 'Finding :position AI tool',
            'findings.*.ai_proposed_root_cause' => 'Finding :position AI-proposed root cause',
            'findings.*.human_validation' => 'Finding :position human validation',
            'findings.*.human_validation_explanation' => 'Finding :position human validation evidence',
            'findings.*.severity' => 'Finding :position severity',
            'findings.*.blast_radius' => 'Finding :position blast radius',
            'findings.*.affected_areas' => 'Finding :position affected features / areas',
            'findings.*.next_step' => 'Finding :position next step',
            'findings.*.tests' => 'Finding :position tests',
            'findings.*.tests.*' => 'Finding :position test',
            'findings.*.tests.*.name' => 'Finding :position test case name',
            'findings.*.tests.*.preconditions' => 'Finding :position test preconditions',
            'findings.*.tests.*.steps' => 'Finding :position test steps',
            'findings.*.tests.*.expected_result' => 'Finding :position test expected result',
            'findings.*.tests.*.actual_result' => 'Finding :position test actual result',
            'findings.*.tests.*.execution_result' => 'Finding :position test execution result',
            'findings.*.tests.*.framework' => 'Finding :position test framework',
            'findings.*.tests.*.script_location' => 'Finding :position test script location',
            'findings.*.tests.*.artifact_evidence' => 'Finding :position test artifact evidence',
            'findings.*.tests.*.execution_evidence' => 'Finding :position test evidence',
            'findings.*.tests.*.fix_validation' => 'Finding :position fix validation',
            'findings.*.tests.*.fix_validation.buggy_result' => 'Finding :position buggy branch result',
            'findings.*.tests.*.fix_validation.bug_free_result' => 'Finding :position bug-free branch result',
            'findings.*.tests.*.fix_validation.evidence' => 'Finding :position fix validation evidence',
            'findings.*.tests.*.fix_validation.status' => 'Finding :position fix validation status',
            'findings.*.tests.*.fix_validation.*' => 'Finding :position fix validation field',
        ];
    }
}
