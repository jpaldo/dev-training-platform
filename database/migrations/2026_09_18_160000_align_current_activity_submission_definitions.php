<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyKeys = [
            'Validation Checklist' => ['artifact_context', 'finding_and_decision'],
            'AI Output Error Detection' => ['ai_tool', 'artifact_type', 'prompt', 'exact_ai_output', 'detection_and_action', 'thread_drive_link'],
            'Pre-acceptance Check' => ['ai_tool', 'artifact_type', 'prompt', 'ai_output', 'higher_stakes_change', 'pre_acceptance_question', 'validation_outcome'],
            'Test-Failure Root-Cause Analysis' => [],
            'Script from Description' => ['execution_investigation'],
            'Regression Prioritization and Coverage Gaps' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'pr_and_requirements', 'regression_priorities'],
        ];

        foreach ($legacyKeys as $title => $keys) {
            $activityIds = DB::table('activities')->where('title', $title)->pluck('id');

            foreach ($activityIds as $activityId) {
                DB::table('activity_requirements')
                    ->where('activity_id', $activityId)
                    ->where('requirement_type', 'submission_field')
                    ->get()
                    ->each(function (object $requirement) use ($keys): void {
                        $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
                        if (! in_array($configuration['key'] ?? null, $keys, true)) {
                            return;
                        }

                        $configuration['deprecated'] = true;
                        DB::table('activity_requirements')->where('id', $requirement->id)->update([
                            'configuration' => json_encode($configuration),
                            'updated_at' => now(),
                        ]);
                    });
            }
        }

        $weekTwo = DB::table('training_weeks')->where('week_number', 2)->pluck('id');
        $current = [
            'AI Output Error Detection' => [
                ['key' => 'validation_submission_id', 'label' => 'QA Validation Lab Record', 'type' => 'text', 'required' => true],
                ['key' => 'actual_truth', 'label' => 'What Was Actually True', 'type' => 'textarea'],
                ['key' => 'how_caught', 'label' => 'How I Caught It', 'type' => 'textarea'],
                ['key' => 'what_i_did', 'label' => 'What I Did', 'type' => 'textarea'],
                ['key' => 'final_result', 'label' => 'Final Result', 'type' => 'textarea'],
            ],
            'Pre-acceptance Check' => [
                ['key' => 'validation_submission_id', 'label' => 'QA Validation Lab Record', 'type' => 'text', 'required' => true],
                ['key' => 'affected_scope', 'label' => 'What Could It Affect?', 'type' => 'textarea'],
                ['key' => 'validation_performed', 'label' => 'Pre-Acceptance Check', 'type' => 'textarea'],
                ['key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'options' => ['Accept', 'Revise', 'Re-prompt AI', 'Reject']],
                ['key' => 'final_notes', 'label' => 'Final Notes', 'type' => 'textarea'],
            ],
        ];

        foreach ($current as $title => $definitions) {
            $activities = DB::table('activities')
                ->whereIn('training_week_id', $weekTwo)
                ->where('title', $title)
                ->get(['id']);

            foreach ($activities as $activity) {
                foreach ($definitions as $position => $definition) {
                    $existing = DB::table('activity_requirements')
                        ->where('activity_id', $activity->id)
                        ->whereNull('participant_path')
                        ->where('requirement_type', 'submission_field')
                        ->get()
                        ->first(function (object $requirement) use ($definition): bool {
                            $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];

                            return ($configuration['key'] ?? null) === $definition['key'];
                        });

                    $configuration = $definition + [
                        'guidance' => [
                            'evidence' => 'Use the activity evidence controls to support this entry with a real artifact, result, link, or record when applicable. Never invent evidence.',
                        ],
                    ];

                    if ($existing) {
                        DB::table('activity_requirements')->where('id', $existing->id)->update([
                            'title' => $definition['label'],
                            'description' => $definition['label'],
                            'is_required' => $definition['required'] ?? true,
                            'position' => $position + 1,
                            'configuration' => json_encode($configuration),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('activity_requirements')->insert([
                            'activity_id' => $activity->id,
                            'participant_path' => null,
                            'requirement_type' => 'submission_field',
                            'title' => $definition['label'],
                            'description' => $definition['label'],
                            'is_required' => $definition['required'] ?? true,
                            'position' => $position + 1,
                            'configuration' => json_encode($configuration),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Historical fields and newly-added current fields are intentionally retained.
        // Reverting them could make existing submissions unreadable.
    }
};
