<?php

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\TrainingGate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Week 2 Gate', 'Week 3 Gate', 'Week 4 Gate'] as $gateTitle) {
            $gate = TrainingGate::query()->where('title', $gateTitle)->first();
            $criterion = $gate?->criteria()->where('criterion_type', 'daily_logs')->first();
            $criterion?->update(['title' => 'Daily-log entries for all five sessions.', 'description' => 'Five daily logs for this training week are complete.']);
        }

        $fields = [
            'Validation Checklist' => [
                ['key' => 'feature_area', 'label' => 'Feature / Area', 'type' => 'text'],
                ['key' => 'environment', 'label' => 'Environment', 'type' => 'text'],
                ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
                ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text'],
                ['key' => 'exact_prompt', 'label' => 'Exact Prompt', 'type' => 'textarea', 'help' => 'Paste the exact prompt you gave the AI. Do not summarize or rewrite it.'],
                ['key' => 'exact_ai_output', 'label' => 'Exact AI Output', 'type' => 'textarea', 'help' => 'Paste the exact AI response/output. Preserve the original content.'],
                ['key' => 'source_check', 'label' => 'Source', 'type' => 'textarea'],
                ['key' => 'scope_check', 'label' => 'Scope', 'type' => 'textarea'],
                ['key' => 'spec_check', 'label' => 'Spec', 'type' => 'textarea'],
                ['key' => 'cross_check', 'label' => 'Cross-check', 'type' => 'textarea'],
                ['key' => 'run_check', 'label' => 'Run', 'type' => 'textarea'],
                ['key' => 'log_check', 'label' => 'Log', 'type' => 'textarea'],
                ['key' => 'issue_caught', 'label' => 'Issue Caught', 'type' => 'textarea'],
                ['key' => 'action_taken', 'label' => 'Action Taken', 'type' => 'textarea'],
            ],
            'AI Output Error Detection' => [
                ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
                ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text'],
                ['key' => 'prompt', 'label' => 'Prompt', 'type' => 'textarea', 'help' => 'Paste the exact prompt used for this activity. Do not summarize or rewrite it.'],
                ['key' => 'how_caught', 'label' => 'How I Caught It', 'type' => 'textarea'],
                ['key' => 'what_i_did', 'label' => 'What I Did', 'type' => 'textarea'],
                ['key' => 'final_result', 'label' => 'Final Result', 'type' => 'textarea'],
                ['key' => 'thread_drive_link', 'label' => 'Thread / Drive Link', 'type' => 'url', 'required' => false],
            ],
            'Pre-acceptance Check' => [
                ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
                ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text'],
                ['key' => 'prompt', 'label' => 'Prompt', 'type' => 'textarea', 'help' => 'Paste the exact prompt used for this activity. Do not summarize or rewrite it.'],
                ['key' => 'ai_output', 'label' => 'AI Output', 'type' => 'textarea', 'help' => 'Paste the exact AI response/output. Preserve the original content.'],
                ['key' => 'pre_acceptance_question', 'label' => 'Pre-acceptance Question', 'type' => 'textarea'],
                ['key' => 'validation_performed', 'label' => 'Validation Performed', 'type' => 'textarea'],
                ['key' => 'decision', 'label' => 'Decision', 'type' => 'textarea'],
                ['key' => 'final_notes', 'label' => 'Final Notes', 'type' => 'textarea'],
            ],
        ];

        foreach ($fields as $title => $definitions) {
            $activity = Activity::query()->where('title', $title)->whereHas('week', fn ($query) => $query->where('week_number', 2))->first();
            if (! $activity) {
                continue;
            }

            foreach ($definitions as $definition) {
                $existing = ActivityRequirement::query()->where('activity_id', $activity->id)->whereNull('participant_path')->where('requirement_type', 'submission_field')->whereJsonContains('configuration->key', $definition['key'])->first();
                if ($existing) {
                    continue;
                }

                $position = ((int) ActivityRequirement::query()->where('activity_id', $activity->id)->max('position')) + 1;
                ActivityRequirement::query()->create([
                    'activity_id' => $activity->id,
                    'participant_path' => null,
                    'requirement_type' => 'submission_field',
                    'title' => $definition['label'],
                    'description' => $definition['label'],
                    'is_required' => $definition['required'] ?? true,
                    'position' => $position,
                    'configuration' => $definition,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Configuration-only additions are intentionally retained on rollback
        // so historical field_values remain readable and no participant data is removed.
    }
};
