<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGateReviewRequest;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\CoachGateReviewPageService;
use App\Services\TrainingExportCoordinator;
use App\Services\TrainingSyncCoordinator;
use App\Services\WeekTwoGateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GateReviewController extends Controller
{
    public function participant(TrainingWeek $trainingWeek, TrainingGate $trainingGate, WeekTwoGateService $service): View
    {
        abort_unless($trainingGate->training_week_id === $trainingWeek->id && $trainingWeek->is_active, 404);
        $participant = request()->user();
        $evaluation = $trainingGate->evaluations()->where('user_id', $participant->id)->with('criterionEvaluations')->first();

        $readiness = $service->readiness($participant, $trainingGate);

        return view('participant.gate', ['trainingWeek' => $trainingWeek, 'gate' => $trainingGate->load('criteria'), 'evaluation' => $evaluation, 'readiness' => $readiness, 'weeklyLoom' => $service->weeklyVideo($participant, $trainingGate), 'readinessSummary' => $this->readinessSummary($participant, $trainingGate, $readiness)]);
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
        $existing = $trainingGate->evaluations()->where('user_id', $participant->id)->first();
        Gate::authorize($existing ? 'update' : 'create', $existing ?? GateEvaluation::class);
        $criteria = $trainingGate->criteria()->where('is_required', true)->get();
        $missing = $criteria->filter(fn (GateCriterion $criterion): bool => ($data['criteria'][$criterion->id] ?? null) !== 'met');
        $readiness = $service->readiness($participant, $trainingGate);
        $weeklyLoomCriterion = $criteria->first(fn (GateCriterion $criterion): bool => in_array($criterion->criterion_type, ['weekly_video', 'weekly_loom'], true));
        if ($data['status'] !== 'passed' && $weeklyLoomCriterion && ($data['criteria'][$weeklyLoomCriterion->id] ?? null) === 'met' && $service->weeklyVideo($participant, $trainingGate)['status'] === 'missing') {
            throw ValidationException::withMessages(['criteria.'.$weeklyLoomCriterion->id => 'The Weekly Video Recording cannot be marked Met until a video is uploaded.']);
        }
        if ($data['status'] === 'passed' && ($missing->isNotEmpty() || $readiness !== [])) {
            throw ValidationException::withMessages(['gate' => "The Week {$trainingWeek->week_number} Gate cannot pass until every required criterion and objective requirement is satisfied."]);
        }
        DB::transaction(function () use (&$evaluation, $trainingGate, $participant, $data, $request, $criteria): void {
            $evaluation = GateEvaluation::query()->updateOrCreate(['training_gate_id' => $trainingGate->id, 'user_id' => $participant->id], ['status' => $data['status'], 'reviewer_id' => $request->user()->id, 'evaluated_at' => now(), 'comments' => $data['comments'] ?? null]);
            foreach ($criteria as $criterion) {
                $evaluation->criterionEvaluations()->updateOrCreate(['gate_criterion_id' => $criterion->id], ['status' => $data['criteria'][$criterion->id] ?? 'not_met', 'reviewer_id' => $request->user()->id, 'evaluated_at' => now()]);
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

    private function readinessSummary(User $participant, TrainingGate $gate, array $readiness): array
    {
        $week = $gate->week;
        $logs = $participant->dailyLogs()->whereHas('activity', fn (Builder $q): Builder => $q->where('training_week_id', $week->id))->count();
        $minimum = 5;
        $summary = [];
        if (array_key_exists('daily_logs', $readiness)) {
            $remaining = max(0, $minimum - $logs);
            $summary[] = ['label' => 'Daily logs', 'progress' => "{$logs} of {$minimum} completed", 'message' => $remaining === 1 ? "One more Week {$week->week_number} daily log is required before this Gate can be passed." : "{$remaining} more Week {$week->week_number} daily logs are required before this Gate can be passed."];
        }
        if (array_key_exists('weekly_video', $readiness) || array_key_exists('weekly_loom', $readiness)) {
            $summary[] = ['label' => 'Weekly Video Recording', 'progress' => 'Missing', 'message' => 'Upload the required weekly video recording before this Gate can be passed.'];
        }
        foreach ($readiness as $key => $reason) {
            if ($key !== 'daily_logs') {
                $summary[] = ['label' => 'Requirements', 'progress' => 'Incomplete', 'message' => 'Complete the outstanding activity and evidence requirements before this Gate can be passed.'];
            }
        }

        return $summary;
    }
}
