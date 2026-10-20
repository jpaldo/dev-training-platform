<?php

use App\Models\TrainingGate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Week 2 Gate', 'Week 3 Gate', 'Week 4 Gate'] as $gateTitle) {
            $gate = TrainingGate::query()->where('title', $gateTitle)->first();
            $gate?->criteria()->where('criterion_type', 'daily_logs')->update([
                'title' => 'Daily-log entries for all five sessions.',
                'description' => 'Five daily logs for this training week are complete.',
            ]);
        }
    }

    public function down(): void
    {
        // Keep historical criterion wording intact on rollback; readiness remains five.
    }
};
