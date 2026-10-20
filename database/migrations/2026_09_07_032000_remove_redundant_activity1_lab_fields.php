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

        if (! $activity) {
            return;
        }

        $keys = ['checklist_outcomes', 'source_check', 'scope_check', 'spec_check', 'cross_check', 'run_check', 'log_check'];
        $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->get()
            ->filter(fn ($requirement) => in_array($requirement->configuration['key'] ?? null, $keys, true))
            ->each->delete();
    }

    public function down(): void
    {
        // Historical SubmissionVersion.field_values are intentionally untouched.
    }
};
