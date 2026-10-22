<?php

namespace App\Http\Controllers;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Http\Requests\StoreGateReviewRequest;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\CoachGateReviewPageService;
use App\Services\ParticipantGatePageService;
use App\Services\TrainingExportCoordinator;
use App\Services\TrainingSyncCoordinator;
use App\Services\WeekTwoGateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GateReviewController extends Controller
{
    public function participant(TrainingWeek $trainingWeek, TrainingGate $trainingGate, ParticipantGatePageService $page): View
    {
        abort_unless($trainingGate->training_week_id === $trainingWeek->id && $trainingWeek->is_active, 404);

        return view('participant.gate', $page->showData($trainingWeek, request()->user(), $trainingGate));
    }

    public function show(TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, CoachGateReviewPageService $page): View
    {
        $this->guard($trainingWeek, $participant, $trainingGate);
        $evaluation = $trainingGate->evaluations()->where('user_id', $participant->id)->first();
        if ($evaluation) {
            Gate::authorize('view', $evaluation);
        }

        return view('coach.gate_review', $page->showData($trainingWeek, $participant, $trainingGate, $evaluation));
    }

    public function update(StoreGateReviewRequest $request, TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, WeekTwoGateService $service, TrainingExportCoordinator $exports, TrainingSyncCoordinator $syncs): RedirectResponse
    {
        $this->guard($trainingWeek, $participant, $trainingGate);
        abort_unless($request->user()->canManageParticipant($participant), 403);
        $data = $request->validated();
        $decision = $request->enum('status', GateEvaluationStatus::class);
        $existing = $trainingGate->evaluations()->where('user_id', $participant->id)->first();
        Gate::authorize($existing ? 'update' : 'create', $existing ?? GateEvaluation::class);
        $criteria = $trainingGate->criteria()->where('is_required', true)->get();
        $missing = $criteria->filter(fn (GateCriterion $criterion): bool => $request->criterionStatus($criterion) !== GateCriterionStatus::Met);
        $readiness = $service->readiness($participant, $trainingGate);
        $weeklyLoomCriterion = $criteria->first(fn (GateCriterion $criterion): bool => in_array($criterion->criterion_type, ['weekly_video', 'weekly_loom'], true));
        if ($decision !== GateEvaluationStatus::Passed && $weeklyLoomCriterion && $request->criterionStatus($weeklyLoomCriterion) === GateCriterionStatus::Met && $service->weeklyVideo($participant, $trainingGate)['status'] === 'missing') {
            throw ValidationException::withMessages(['criteria.'.$weeklyLoomCriterion->id => 'The Weekly Video Recording cannot be marked Met until a video is uploaded.']);
        }
        if ($decision === GateEvaluationStatus::Passed && ($missing->isNotEmpty() || $readiness !== [])) {
            throw ValidationException::withMessages(['gate' => "The Week {$trainingWeek->week_number} Gate cannot pass until every required criterion and objective requirement is satisfied."]);
        }
        DB::transaction(function () use (&$evaluation, $trainingGate, $participant, $data, $decision, $request, $criteria): void {
            $evaluation = GateEvaluation::query()->updateOrCreate(['training_gate_id' => $trainingGate->id, 'user_id' => $participant->id], ['status' => $decision, 'reviewer_id' => $request->user()->id, 'evaluated_at' => now(), 'comments' => $data['comments'] ?? null]);
            foreach ($criteria as $criterion) {
                $evaluation->criterionEvaluations()->updateOrCreate(['gate_criterion_id' => $criterion->id], ['status' => $request->criterionStatus($criterion), 'reviewer_id' => $request->user()->id, 'evaluated_at' => now()]);
            }
        });
        $exports->queueSummaryReports($participant);
        $syncs->request($participant, 'gate_updated');

        return back()->with('status', 'Gate evaluation saved.');
    }

    private function guard(TrainingWeek $week, User $participant, TrainingGate $gate): void
    {
        abort_unless($week->id === $gate->training_week_id && in_array($week->week_number, [2, 3, 4], true) && $participant->role === 'participant', 404);
    }
}
