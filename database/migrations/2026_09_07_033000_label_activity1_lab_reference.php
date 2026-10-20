<?php

use App\Models\Activity;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $activity = Activity::query()
            ->where('title', 'Validation Checklist')
            ->whereHas('week', fn ($query) => $query->where('week_number', 2))
            ->first();

        $requirement = $activity?->requirements()
            ->where('requirement_type', 'submission_field')
            ->get()
            ->first(fn ($item) => ($item->configuration['key'] ?? null) === 'validation_submission_id');

        if ($requirement) {
            $configuration = $requirement->configuration;
            $configuration['label'] = 'QA Validation Lab Record';
            $requirement->update(['title' => 'QA Validation Lab Record', 'description' => 'Select one of your completed participant-owned Lab records.', 'configuration' => $configuration]);
        }
    }

    public function down(): void
    {
        // Keep the clearer participant-facing label on rollback.
    }
};
