<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\FilterCoachParticipantsRequest;
use App\Models\Program;
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
     * A participant's work in one curriculum. The participant is bound through the curriculum's participants, so
     * someone who is not in the curriculum is not found; only an admin or the participant's current mentor in the
     * curriculum may open it.
     */
    public function show(Request $request, Program $program, User $participant, CoachParticipantPageService $page): View
    {
        abort_unless($participant->hasAnyRole(UserRole::Participant), 404);
        abort_unless($request->user()->mentorsParticipantInProgram($participant, $program), 403);

        return view('coach.participant', $page->showData($request->user(), $program->loadScheduleBounds(), $participant));
    }
}
