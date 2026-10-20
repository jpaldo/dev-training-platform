<?php

use App\Models\ActivityRequirement;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\TrainingGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = [
            2 => 'Participant must submit a Loom walkthrough demonstrating their actual AI-output validation work and explaining their validation process, evidence, issue caught, and final QA decision.',
            3 => 'Participant must submit a Loom walkthrough demonstrating their actual AI-assisted QA work and explaining their failure analysis, test generation and/or regression reasoning, evidence reviewed, and final conclusion.',
            4 => 'Participant must submit a Loom walkthrough demonstrating their actual Level 3 QA work, reusable asset/workflow, and QA reasoning.',
        ];

        DB::transaction(function () use ($descriptions): void {
            foreach ($descriptions as $weekNumber => $description) {
                $gate = TrainingGate::query()->whereHas('week', fn ($query) => $query->where('week_number', $weekNumber))->first();
                $activity = $gate?->week?->activities()->where('position', 3)->first();
                if (! $gate || ! $activity) {
                    continue;
                }

                $evidenceRequirement = $this->weeklyLoomEvidence($activity, $weekNumber);
                $criterion = $gate->criteria()->where('criterion_type', 'loom_link')->first();
                if ($criterion) {
                    $criterion->update(['criterion_type' => 'weekly_loom']);
                } else {
                    $criterion = $gate->criteria()->firstOrNew(['criterion_type' => 'weekly_loom']);
                }
                $criterion->fill([
                    'position' => $criterion->position ?: ($gate->criteria()->max('position') + 1),
                    'title' => 'Required Weekly Loom Video',
                    'description' => $description,
                    'is_required' => true,
                    'configuration' => ['activity_position' => 3, 'evidence_requirement_id' => $evidenceRequirement->id],
                ])->save();
            }
        });
    }

    public function down(): void
    {
        GateCriterion::query()->where('criterion_type', 'weekly_loom')->delete();
        ActivityRequirement::query()->where('requirement_type', 'gate_evidence')->each(function (ActivityRequirement $requirement): void {
            $requirement->delete();
        });
    }

    private function weeklyLoomEvidence($activity, int $weekNumber): EvidenceRequirement
    {
        $requirement = ActivityRequirement::query()->updateOrCreate(
            ['activity_id' => $activity->id, 'participant_path' => null, 'requirement_type' => 'gate_evidence', 'position' => 90],
            ['title' => 'Required Weekly Loom Video', 'description' => "Submit the required Week {$weekNumber} Loom walkthrough for this Gate.", 'is_required' => true, 'configuration' => ['key' => 'weekly_loom', 'week' => $weekNumber]],
        );

        return EvidenceRequirement::query()->updateOrCreate(
            ['activity_requirement_id' => $requirement->id, 'position' => 1],
            ['title' => 'Required Weekly Loom Video', 'description' => "Loom walkthrough for Week {$weekNumber} Gate review.", 'evidence_type' => 'video', 'is_required' => true, 'minimum_count' => 1, 'maximum_count' => 1, 'configuration' => ['gate_criterion_type' => 'weekly_loom']],
        );
    }
};
