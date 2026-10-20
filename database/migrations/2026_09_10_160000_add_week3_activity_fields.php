<?php

use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fields = [
            'Test-Failure Root-Cause Analysis' => [
                ['feature_area', 'Feature / Area', 'text'], ['environment', 'Environment', 'text'], ['test_scenario', 'Test / Scenario', 'textarea'], ['failure_description', 'Failure Description', 'textarea'], ['expected_result', 'Expected Result', 'textarea'], ['actual_result', 'Actual Result', 'textarea'], ['ai_tool', 'AI Tool', 'text'], ['artifact_type', 'Artifact Type', 'text'], ['exact_prompt', 'Exact Prompt', 'textarea'], ['exact_ai_output', 'Exact AI Output', 'textarea'], ['failed_runs', 'Five Failed Runs and Evidence-Backed Analysis', 'textarea'], ['diagnostic_steps', 'Diagnostic Steps Performed', 'textarea'], ['evidence_reviewed', 'Evidence Reviewed', 'textarea'], ['ai_suggested_root_causes', 'AI-Suggested Root Cause(s)', 'textarea'], ['actual_root_cause', 'Actual Root Cause', 'textarea'], ['shared_cause', 'Shared Cause or Explicit No-Shared-Cause Conclusion', 'textarea'], ['root_cause_validation', 'How Root Cause Was Validated', 'textarea'], ['severity', 'Severity', 'text'], ['blast_radius', 'Blast Radius', 'textarea'], ['affected_areas', 'Affected Areas', 'textarea'], ['action_taken', 'Action Taken', 'textarea'], ['impact_next_step', 'Fix or Next Diagnostic Step', 'textarea'], ['final_result', 'Final Result', 'textarea'], ['qa_conclusion', 'QA Conclusion / Recommendation', 'textarea'],
            ],
            'Script from Description' => [
                ['feature_requirement_description', 'Feature / Requirement Description', 'textarea'], ['requirement_mapping', 'Requirement and Jira-to-test Mapping', 'textarea'], ['acceptance_criteria', 'Acceptance Criteria / Expected Behavior', 'textarea'], ['ai_tool', 'AI Tool', 'text'], ['artifact_type', 'Artifact Type', 'text'], ['exact_prompt', 'Exact Prompt', 'textarea'], ['exact_ai_output', 'Exact AI Output', 'textarea'], ['generated_test', 'Generated Script / Test Cases', 'textarea'], ['ai_assumptions', 'Assumptions Made by AI', 'textarea'], ['validation_performed', 'Validation Performed', 'textarea'], ['issues_corrections', 'Issues / Corrections Identified', 'textarea'], ['corrected_script', 'Corrected Script / Final Version', 'textarea'], ['execution_result', 'Execution Result', 'text'], ['test_results_observations', 'Test Results / Observations', 'textarea'], ['final_qa_decision', 'Final QA Decision', 'textarea'], ['execution_investigation', 'Execution Evidence and Investigation', 'textarea'], ['repository_thread_link', 'Repository / Thread / Drive Link', 'url', false],
            ],
            'Regression Prioritization and Coverage Gaps' => [
                ['feature_change', 'Feature / Change Being Tested', 'textarea'], ['affected_areas', 'Affected Areas', 'textarea'], ['risk_business_impact', 'Risk / Business Impact', 'textarea'], ['ai_tool', 'AI Tool', 'text'], ['artifact_type', 'Artifact Type', 'text'], ['exact_prompt', 'Exact Prompt', 'textarea'], ['exact_ai_output', 'Exact AI Output', 'textarea'], ['ai_generated_prioritization', 'AI-Generated Regression Prioritization', 'textarea'], ['ai_risk_reasoning', 'AI Risk Reasoning', 'textarea'], ['validation_cross_check', 'Validation / Cross-Check Performed', 'textarea'], ['qa_adjusted_priority', 'QA-Adjusted Priority', 'textarea'], ['priority_change_reason', 'Reason for Priority Changes', 'textarea'], ['pr_and_requirements', 'PR, Changed Files, and Requirements Reviewed', 'textarea'], ['coverage_gaps', 'Coverage Gaps Identified', 'textarea'], ['high_risk_missing_tests', 'High-Risk Missing Tests', 'textarea'], ['regression_priorities', 'Validated Regression Priorities and Flaky-Test Analysis', 'textarea'], ['final_regression_plan', 'Regression Test Matrix / Final Regression Plan', 'textarea'], ['final_recommendation', 'Final Recommendation', 'textarea'], ['final_notes', 'Final Notes', 'textarea'],
            ],
        ];

        $seeder = new QaTrainingProgramSeeder;
        foreach ($fields as $title => $definitions) {
            foreach (DB::table('activities')->where('title', $title)->pluck('id') as $activityId) {
                foreach ($definitions as $position => $definition) {
                    [$key, $label, $type, $required] = array_pad($definition, 4, true);
                    $existing = DB::table('activity_requirements')->where('activity_id', $activityId)->where('requirement_type', 'submission_field')->whereJsonContains('configuration->key', $key)->first();
                    $configuration = $existing ? (json_decode($existing->configuration ?: '{}', true) ?: []) : [];
                    $configuration = array_merge($configuration, ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $required, 'guidance' => $seeder->fieldGuidance($title, $key)]);
                    $attributes = ['title' => $label, 'description' => $label, 'is_required' => $required, 'position' => $position + 1, 'configuration' => json_encode($configuration)];
                    if ($existing) {
                        DB::table('activity_requirements')->where('id', $existing->id)->update($attributes);
                    } else {
                        DB::table('activity_requirements')->insert($attributes + ['activity_id' => $activityId, 'participant_path' => null, 'requirement_type' => 'submission_field', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Additive field configuration is retained so historical submissions remain readable.
    }
};
