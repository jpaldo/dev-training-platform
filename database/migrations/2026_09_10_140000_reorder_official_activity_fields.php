<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orders = [
            'Validation Checklist' => ['artifact_context', 'feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'validation_submission_id', 'issue_caught', 'action_taken', 'finding_and_decision'],
            'AI Output Error Detection' => ['ai_tool', 'artifact_type', 'prompt', 'exact_ai_output', 'actual_truth', 'how_caught', 'what_i_did', 'detection_and_action', 'final_result', 'thread_drive_link'],
            'Pre-acceptance Check' => ['ai_tool', 'artifact_type', 'higher_stakes_change', 'affected_scope', 'prompt', 'ai_output', 'pre_acceptance_question', 'validation_performed', 'validation_outcome', 'decision', 'final_notes'],
            'Test-Failure Root-Cause Analysis' => ['failed_runs', 'shared_cause', 'impact_next_step'],
            'Script from Description' => ['requirement_mapping', 'generated_test', 'execution_investigation'],
            'Regression Prioritization and Coverage Gaps' => ['pr_and_requirements', 'regression_priorities', 'coverage_gaps'],
            'Build a Reusable Asset' => ['asset_name_purpose', 'asset_inputs_instructions', 'asset_validation_reuse', 'team_library_location'],
            'Mock Level 3 Interview' => ['interview_evidence', 'partner_feedback', 'coach_feedback', 'daily_log_reference'],
            'Formal Reassessment' => ['strict_interview_transcript', 'assessment_tracker_result', 'updated_level_confidence'],
        ];

        foreach ($orders as $title => $keys) {
            $activityIds = DB::table('activities')->where('title', $title)->pluck('id');
            foreach ($activityIds as $activityId) {
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
