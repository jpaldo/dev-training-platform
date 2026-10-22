<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\FilterCoachParticipantsRequest;
use App\Models\User;
use App\Services\CoachParticipantListPageService;
use App\Services\CoachParticipantPageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CoachParticipantController extends Controller
{
    public function index(FilterCoachParticipantsRequest $request, CoachParticipantListPageService $page): View
    {
        return view('coach.participants', $page->indexData($request->user(), $request->validated()));
    }

    public function show(Request $request, User $participant, CoachParticipantPageService $page): View
    {
        abort_unless($participant->hasAnyRole(UserRole::Participant), 404);
        Gate::authorize('view', $participant);

        return view('coach.participant', $page->showData($request->user(), $participant));
    }
}
