<?php

namespace App\Http\Controllers;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreGateReviewRequest;
use App\Models\GateEvaluation;
use App\Models\Program;
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
    /**
     * The participant's Gate page within the curriculum. The route has already checked that they are in the curriculum
     * and that the week is one of its weeks.
     */
    public function participant(Program $program, TrainingWeek $trainingWeek, TrainingGate $trainingGate, ParticipantGatePageService $page): View
    {
        abort_unless($trainingGate->training_week_id === $trainingWeek->id, 404);

        return view('participant.gate', $page->showData($program, $trainingWeek, request()->user(), $trainingGate));
    }

    /**
     * The coach Gate review of a participant's week in the curriculum, for an admin or the participant's current mentor
     * there; a participant who is not in the curriculum is not found.
     */
    public function show(Program $program, TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, CoachGateReviewPageService $page): View
    {
        $this->guard($program, $trainingWeek, $participant, $trainingGate);
        abort_unless(request()->user()->mentorsParticipantInProgram($participant, $program), 403);

        return view('coach.gate_review', $page->showData($program, $trainingWeek, $participant, $trainingGate, $this->evaluation($participant, $trainingGate)));
    }

    public function update(StoreGateReviewRequest $request, Program $program, TrainingWeek $trainingWeek, User $participant, TrainingGate $trainingGate, GateReviewService $reviews): RedirectResponse
    {
        $this->guard($program, $trainingWeek, $participant, $trainingGate);
        abort_unless($request->user()->mentorsParticipantInProgram($participant, $program), 403);
        $existing = $this->evaluation($participant, $trainingGate);
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

    /**
     * The participant's evaluation of the Gate, if any.
     */
    private function evaluation(User $participant, TrainingGate $gate): ?GateEvaluation
    {
        return $gate->evaluations()->where('user_id', $participant->id)->first();
    }

    private function guard(Program $program, TrainingWeek $week, User $participant, TrainingGate $gate): void
    {
        abort_unless(
            $week->id === $gate->training_week_id
                && $participant->role === UserRole::Participant
                && $program->includesParticipant($participant),
            404,
        );
    }
}
