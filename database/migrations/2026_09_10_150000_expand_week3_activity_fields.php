<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orders = [
            'Test-Failure Root-Cause Analysis' => ['feature_area', 'environment', 'test_scenario', 'failure_description', 'expected_result', 'actual_result', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'failed_runs', 'diagnostic_steps', 'evidence_reviewed', 'ai_suggested_root_causes', 'actual_root_cause', 'shared_cause', 'root_cause_validation', 'severity', 'blast_radius', 'affected_areas', 'action_taken', 'impact_next_step', 'final_result', 'qa_conclusion'],
            'Script from Description' => ['feature_requirement_description', 'requirement_mapping', 'acceptance_criteria', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'generated_test', 'ai_assumptions', 'validation_performed', 'issues_corrections', 'corrected_script', 'execution_result', 'test_results_observations', 'final_qa_decision', 'execution_investigation', 'repository_thread_link'],
            'Regression Prioritization and Coverage Gaps' => ['feature_change', 'affected_areas', 'risk_business_impact', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'ai_generated_prioritization', 'ai_risk_reasoning', 'validation_cross_check', 'qa_adjusted_priority', 'priority_change_reason', 'pr_and_requirements', 'coverage_gaps', 'high_risk_missing_tests', 'regression_priorities', 'final_regression_plan', 'final_recommendation', 'final_notes'],
        ];

        foreach ($orders as $title => $keys) {
            foreach (DB::table('activities')->where('title', $title)->pluck('id') as $activityId) {
                foreach ($keys as $position => $key) {
                    DB::table('activity_requirements')->where('activity_id', $activityId)->where('requirement_type', 'submission_field')->whereJsonContains('configuration->key', $key)->update(['position' => $position + 1]);
                }
            }
        }
    }

    public function down(): void
    {
        // Ordering metadata is intentionally not reverted; submission data is unaffected.
    }
};
