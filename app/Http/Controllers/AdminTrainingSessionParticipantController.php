<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingSessionParticipantsRequest;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminTrainingSessionParticipantController extends Controller
{
    public function store(StoreTrainingSessionParticipantsRequest $request, TrainingSession $trainingSession, TrainingSessionService $sessions): RedirectResponse
    {
        $addedCount = $sessions->addParticipants($trainingSession, $request->participantIds(), $request->user());

        return $this->toParticipantsTab($trainingSession)
            ->with('status', 'Added '.$addedCount.' '.Str::plural('participant', $addedCount).'.');
    }

    /**
     * The participant is bound through the session's participants, so someone who is not in the session is not found.
     */
    public function destroy(Request $request, TrainingSession $trainingSession, User $participant, TrainingSessionService $sessions): RedirectResponse
    {
        Gate::authorize('manage', $trainingSession);

        $sessions->removeParticipant($trainingSession, $participant, $request->user());

        return $this->toParticipantsTab($trainingSession)
            ->with('status', "Removed {$participant->name} from the session.");
    }

    private function toParticipantsTab(TrainingSession $trainingSession): RedirectResponse
    {
        return redirect()->to(route('admin.sessions.show', $trainingSession).'#participants');
    }
}
