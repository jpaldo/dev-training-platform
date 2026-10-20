<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $deprecated = [
            'Validation Checklist' => ['artifact_context', 'finding_and_decision'],
            'AI Output Error Detection' => ['detection_and_action'],
            'Pre-acceptance Check' => ['validation_outcome'],
            'Script from Description' => ['execution_investigation'],
            'Regression Prioritization and Coverage Gaps' => ['pr_and_requirements', 'regression_priorities'],
        ];

        foreach ($deprecated as $title => $keys) {
            $activityIds = DB::table('activities')->where('title', $title)->pluck('id');
            foreach ($activityIds as $activityId) {
                $requirements = DB::table('activity_requirements')
                    ->where('activity_id', $activityId)
                    ->where('requirement_type', 'submission_field')
                    ->get();

                foreach ($requirements as $requirement) {
                    $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
                    if (in_array($configuration['key'] ?? null, $keys, true)) {
                        $configuration['deprecated'] = true;
                        DB::table('activity_requirements')->where('id', $requirement->id)->update([
                            'configuration' => json_encode($configuration),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'artifact_context', 'finding_and_decision', 'detection_and_action',
            'validation_outcome', 'execution_investigation', 'pr_and_requirements',
            'regression_priorities',
        ];

        DB::table('activity_requirements')->where('requirement_type', 'submission_field')->get()->each(function ($requirement) use ($keys): void {
            $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
            if (in_array($configuration['key'] ?? null, $keys, true)) {
                unset($configuration['deprecated']);
                DB::table('activity_requirements')->where('id', $requirement->id)->update([
                    'configuration' => json_encode($configuration),
                    'updated_at' => now(),
                ]);
            }
        });
    }
};
