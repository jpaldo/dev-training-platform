<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ParticipantTrainingSessionPageService;
use Illuminate\Http\Request;
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
}
