<?php

namespace App\Http\Controllers;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreGateReviewRequest;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
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
    /**
     * The participant's Gate page within the session. The route has already checked that they are in the session and
     * that the week is one of its weeks.
     */
    public function participant(TrainingSession $trainingSession, TrainingWeek $trainingWeek, TrainingGate $trainingGate, ParticipantGatePageService $page): View
    {
        abort_unless($trainingGate->training_week_id === $trainingWeek->id, 404);

        return view('participant.gate', $page->showData($trainingSession, $trainingWeek, request()->user(), $trainingGate));
    }

    /**
     * The coach Gate review of a participant's week in the session; a participant who is not in the session is not
     * found.
     */
    public function show(TrainingSession $trainingSession, TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, CoachGateReviewPageService $page): View
    {
        $this->guard($trainingSession, $trainingWeek, $participant, $trainingGate);
        $evaluation = $this->evaluation($trainingSession, $participant, $trainingGate);
        if ($evaluation) {
            Gate::authorize('view', $evaluation);
        }

        return view('coach.gate_review', $page->showData($trainingSession, $trainingWeek, $participant, $trainingGate, $evaluation, request()->user()));
    }

    public function update(StoreGateReviewRequest $request, TrainingSession $trainingSession, TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, GateReviewService $reviews): RedirectResponse
    {
        $this->guard($trainingSession, $trainingWeek, $participant, $trainingGate);
        abort_unless($request->user()->canManageParticipant($participant), 403);
        $existing = $this->evaluation($trainingSession, $participant, $trainingGate);
        Gate::authorize($existing ? 'update' : 'create', $existing ?? GateEvaluation::class);
        $reviews->record(
            trainingSession: $trainingSession,
            gate: $trainingGate,
            participant: $participant,
            reviewer: $request->user(),
            decision: $request->enum('status', GateEvaluationStatus::class),
            comments: $request->validated('comments'),
            criterionStatuses: $request->criterionStatuses(),
        );

        return back()->with('status', 'Gate evaluation saved.');
    }

    /**
     * The participant's evaluation of the Gate in the session, if any.
     */
    private function evaluation(TrainingSession $trainingSession, User $participant, TrainingGate $gate): ?GateEvaluation
    {
        return $gate->evaluations()->where('training_session_id', $trainingSession->id)->where('user_id', $participant->id)->first();
    }

    private function guard(TrainingSession $trainingSession, TrainingWeek $week, User $participant, TrainingGate $gate): void
    {
        abort_unless(
            $week->id === $gate->training_week_id
                && $participant->role === UserRole::Participant
                && $trainingSession->includesParticipant($participant),
            404,
        );
    }
}
