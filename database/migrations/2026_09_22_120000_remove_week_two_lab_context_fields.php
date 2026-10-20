<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateKeys = [
            'artifact_context',
            'feature_area',
            'environment',
            'ai_tool',
            'artifact_type',
            'exact_prompt',
            'exact_ai_output',
            'prompt',
            'ai_output',
        ];

        $activityIds = DB::table('activities')
            ->join('training_weeks', 'training_weeks.id', '=', 'activities.training_week_id')
            ->where('training_weeks.week_number', 2)
            ->whereIn('activities.title', ['Validation Checklist', 'AI Output Error Detection', 'Pre-acceptance Check'])
            ->pluck('activities.id');

        DB::table('activity_requirements')
            ->whereIn('activity_id', $activityIds)
            ->where('requirement_type', 'submission_field')
            ->get()
            ->each(function (object $requirement) use ($duplicateKeys): void {
                $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
                if (! in_array($configuration['key'] ?? null, $duplicateKeys, true)) {
                    return;
                }

                $configuration['deprecated'] = true;
                DB::table('activity_requirements')->where('id', $requirement->id)->update([
                    'configuration' => json_encode($configuration),
                    'updated_at' => now(),
                ]);
            });

        DB::table('activity_requirements')
            ->whereIn('activity_id', $activityIds)
            ->where('requirement_type', 'submission_field')
            ->get()
            ->each(function (object $requirement): void {
                $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
                if (($configuration['key'] ?? null) !== 'validation_submission_id') {
                    return;
                }

                $configuration['required'] = true;
                DB::table('activity_requirements')->where('id', $requirement->id)->update([
                    'is_required' => true,
                    'configuration' => json_encode($configuration),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Historical submission field_values are intentionally preserved.
    }
};
