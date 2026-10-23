<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingSessionMentorRequest;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\MentorAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminTrainingSessionMentorController extends Controller
{
    public function store(StoreTrainingSessionMentorRequest $request, TrainingSession $trainingSession, MentorAssignmentService $mentorAssignments): RedirectResponse
    {
        $addedCount = $mentorAssignments->addMentors($trainingSession, $request->mentorIds(), $request->user());

        return $this->toMentorsTab($trainingSession)
            ->with('status', 'Added '.$addedCount.' '.Str::plural('mentor', $addedCount).'.');
    }

    /**
     * The mentor is bound through the session's mentors, so someone who is not a mentor of the session is not found.
     * Their current participants in the session become unassigned.
     */
    public function destroy(Request $request, TrainingSession $trainingSession, User $mentor, MentorAssignmentService $mentorAssignments): RedirectResponse
    {
        Gate::authorize('manage', $trainingSession);

        $mentorAssignments->removeMentor($trainingSession, $mentor, $request->user());

        return $this->toMentorsTab($trainingSession)
            ->with('status', "Removed {$mentor->name} as a mentor of the session.");
    }

    private function toMentorsTab(TrainingSession $trainingSession): RedirectResponse
    {
        return redirect()->to(route('admin.sessions.show', $trainingSession).'#mentors');
    }
}
