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
            $activityIds = DB::table('activities')->where('title', $title)->pluck('id');
            foreach ($activityIds as $activityId) {
                $requirements = DB::table('activity_requirements')
                    ->where('activity_id', $activityId)
                    ->where('requirement_type', 'submission_field')
                    ->orderBy('position')
                    ->get();
                $ordered = $requirements->sortBy(fn ($requirement): int => in_array(json_decode($requirement->configuration ?: '{}', true)['key'] ?? null, $keys, true) ? 0 : 1)->values();
                foreach ($ordered as $position => $requirement) {
                    $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
                    $isContext = in_array($configuration['key'] ?? null, $keys, true);
                    if ($isContext) {
                        $configuration['section'] = 'artifact_context';
                    }
                    DB::table('activity_requirements')->where('id', $requirement->id)->update([
                        'position' => $position + 1,
                        'configuration' => json_encode($configuration),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('activity_requirements')->where('requirement_type', 'submission_field')->get()->each(function ($requirement): void {
            $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
            if (($configuration['section'] ?? null) === 'artifact_context') {
                unset($configuration['section']);
                DB::table('activity_requirements')->where('id', $requirement->id)->update([
                    'configuration' => json_encode($configuration),
                    'updated_at' => now(),
                ]);
            }
        });
    }
};
