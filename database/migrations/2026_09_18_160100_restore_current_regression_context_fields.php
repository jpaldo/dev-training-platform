<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $currentKeys = ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output'];

        DB::table('activities')
            ->where('title', 'Regression Prioritization and Coverage Gaps')
            ->pluck('id')
            ->each(function (int $activityId) use ($currentKeys): void {
                DB::table('activity_requirements')
                    ->where('activity_id', $activityId)
                    ->where('requirement_type', 'submission_field')
                    ->get()
                    ->each(function (object $requirement) use ($currentKeys): void {
                        $configuration = json_decode($requirement->configuration ?: '{}', true) ?: [];
                        if (! in_array($configuration['key'] ?? null, $currentKeys, true)) {
                            return;
                        }

                        unset($configuration['deprecated']);
                        DB::table('activity_requirements')->where('id', $requirement->id)->update([
                            'configuration' => json_encode($configuration),
                            'updated_at' => now(),
                        ]);
                    });
            });
    }

    public function down(): void
    {
        // Current fields must remain available to participant forms on rollback.
    }
};
