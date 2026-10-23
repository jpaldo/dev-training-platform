<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\FilterCoachParticipantsRequest;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\CoachParticipantListPageService;
use App\Services\CoachParticipantPageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoachParticipantController extends Controller
{
    public function index(FilterCoachParticipantsRequest $request, CoachParticipantListPageService $page): View
    {
        return view('coach.participants', $page->indexData($request->user(), $request->validated()));
    }

    /**
     * A participant's work in one training session. The participant is bound through the session's participants, so
     * someone who is not in the session is not found; only an admin or the participant's current mentor in the session
     * may open it.
     */
    public function show(Request $request, TrainingSession $trainingSession, User $participant, CoachParticipantPageService $page): View
    {
        abort_unless($participant->hasAnyRole(UserRole::Participant), 404);
        abort_unless($request->user()->mentorsParticipantInSession($participant, $trainingSession), 403);

        return view('coach.participant', $page->showData($request->user(), $trainingSession, $participant));
    }
}
