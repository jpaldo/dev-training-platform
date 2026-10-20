<?php

use App\Models\ActivityRequirement;
use App\Models\GateCriterion;
use App\Models\TrainingGate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = [
            2 => 'Upload a video recording demonstrating your actual QA work for Week 2 and explaining your validation process, evidence reviewed, findings, and final QA decision.',
            3 => 'Upload a video recording demonstrating your actual QA work for Week 3 and explaining your failure analysis, test generation, regression reasoning, evidence reviewed, and final conclusion.',
            4 => 'Upload a video recording demonstrating your actual QA work for Week 4, reusable asset/workflow, and QA reasoning.',
        ];
        foreach (TrainingGate::query()->with('week')->get() as $gate) {
            $week = (int) $gate->week?->week_number;
            if (! isset($descriptions[$week])) {
                continue;
            }
            $legacy = $gate->criteria()->whereIn('criterion_type', ['loom_link', 'weekly_loom'])->first();
            $position = $legacy?->position ?? ($week === 2 ? 6 : ($week === 3 ? 5 : 13));
            $criterion = $gate->criteria()->updateOrCreate(['criterion_type' => 'weekly_video'], ['position' => $position, 'title' => 'Required Weekly Video Recording', 'description' => $descriptions[$week], 'is_required' => true, 'configuration' => []]);
            if ($legacy && $legacy->id !== $criterion->id) {
                $legacy->update(['title' => 'Historical Weekly Loom (legacy)', 'is_required' => false]);
            }
            $gate->week->activities()->where('position', 3)->get()->each(function ($activity): void {
                ActivityRequirement::query()->where('activity_id', $activity->id)->where('requirement_type', 'gate_evidence')->update(['is_required' => false]);
            });
        }
    }

    public function down(): void
    {
        GateCriterion::query()->where('criterion_type', 'weekly_video')->delete();
    }
};
