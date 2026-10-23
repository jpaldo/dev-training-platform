<?php

namespace App\Http\Controllers;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreGateReviewRequest;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\CoachGateReviewPageService;
use App\Services\GateReviewService;
use App\Services\ParticipantGatePageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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

        return view('coach.gate_review', $page->showData($trainingWeek, $participant, $trainingGate, $evaluation, request()->user()));
    }

    public function update(StoreGateReviewRequest $request, TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, GateReviewService $reviews): RedirectResponse
    {
        $this->guard($trainingWeek, $participant, $trainingGate);
        abort_unless($request->user()->canManageParticipant($participant), 403);
        $existing = $trainingGate->evaluations()->where('user_id', $participant->id)->first();
        Gate::authorize($existing ? 'update' : 'create', $existing ?? GateEvaluation::class);
        $reviews->record(
            gate: $trainingGate,
            participant: $participant,
            reviewer: $request->user(),
            decision: $request->enum('status', GateEvaluationStatus::class),
            comments: $request->validated('comments'),
            criterionStatuses: $request->criterionStatuses(),
        );

        return back()->with('status', 'Gate evaluation saved.');
    }

    private function guard(TrainingWeek $week, User $participant, TrainingGate $gate): void
    {
        abort_unless($week->id === $gate->training_week_id && $participant->role === UserRole::Participant, 404);
    }
}
