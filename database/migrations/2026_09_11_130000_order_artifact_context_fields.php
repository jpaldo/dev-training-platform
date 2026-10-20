<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $context = [
            'Validation Checklist' => ['feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
            'AI Output Error Detection' => ['ai_tool', 'artifact_type', 'prompt', 'exact_ai_output'],
            'Pre-acceptance Check' => ['ai_tool', 'artifact_type', 'prompt', 'ai_output'],
            'Test-Failure Root-Cause Analysis' => ['feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
            'Script from Description' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
            'Regression Prioritization and Coverage Gaps' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'],
        ];

        foreach ($context as $title => $keys) {
            foreach (DB::table('activities')->where('title', $title)->pluck('id') as $activityId) {
                $requirements = DB::table('activity_requirements')->where('activity_id', $activityId)->where('requirement_type', 'submission_field')->orderBy('position')->get();
                $ordered = $requirements->sortBy(fn ($requirement): int => in_array(json_decode($requirement->configuration ?: '{}', true)['key'] ?? null, $keys, true) ? 0 : 1)->values();
                foreach ($ordered as $position => $requirement) {
                    DB::table('activity_requirements')->where('id', $requirement->id)->update(['position' => $position + 1, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Ordering metadata is reversible only by restoring the prior seeded order;
        // submitted field values are never affected by this migration.
    }
};
