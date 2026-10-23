<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ParticipantTrainingSessionPageService;
use App\Services\TrainingSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TrainingSessionController extends Controller
{
    /**
     * The session details page for a participant in the session. The route has already checked that the user is a
     * participant who can view the session.
     */
    public function show(Request $request, TrainingSession $trainingSession, ParticipantTrainingSessionPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('training-sessions.show', $page->showData($trainingSession, $user));
    }

    /**
     * Join the session in one click and open its page. Joining again (a double click, or a second tab) changes nothing
     * and opens the page too; everyone else must pass the `join` policy.
     */
    public function join(Request $request, TrainingSession $trainingSession, TrainingSessionService $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($trainingSession->includesParticipant($user)) {
            return redirect()->route('training.sessions.show', $trainingSession)
                ->with('status', "You are already in {$trainingSession->title}.");
        }

        Gate::authorize('join', $trainingSession);

        $sessions->join($trainingSession, $user);

        return redirect()->route('training.sessions.show', $trainingSession)
            ->with('status', "You joined {$trainingSession->title}.");
    }
}
