<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GateReviewService
{
    public function __construct(
        private readonly TrainingExportCoordinator $exports,
        private readonly TrainingSyncCoordinator $syncs,
    ) {}

    /**
     * Save a mentor's or admin's review of a participant's weekly checklist: the decision (completed or incomplete),
     * the comments and the status of each required checklist item (an unticked item as not met) in one transaction,
     * then queue the participant's summary reports and request a Drive sync.
     *
     * @param  array<int, GateCriterionStatus>  $criterionStatuses  keyed by required criterion id
     */
    public function record(TrainingGate $gate, User $participant, User $reviewer, GateEvaluationStatus $decision, ?string $comments, array $criterionStatuses): GateEvaluation
    {
        $evaluation = DB::transaction(function () use ($gate, $participant, $reviewer, $decision, $comments, $criterionStatuses): GateEvaluation {
            $evaluation = GateEvaluation::query()->updateOrCreate(
                ['training_gate_id' => $gate->id, 'user_id' => $participant->id],
                ['status' => $decision, 'reviewer_id' => $reviewer->id, 'evaluated_at' => now(), 'comments' => $comments],
            );
            foreach ($criterionStatuses as $criterionId => $status) {
                $evaluation->criterionEvaluations()->updateOrCreate(
                    ['gate_criterion_id' => $criterionId],
                    ['status' => $status, 'reviewer_id' => $reviewer->id, 'evaluated_at' => now()],
                );
            }

            return $evaluation;
        });
        $this->exports->queueSummaryReports($participant);
        $this->syncs->request($participant, 'gate_updated');

        return $evaluation;
    }
}
