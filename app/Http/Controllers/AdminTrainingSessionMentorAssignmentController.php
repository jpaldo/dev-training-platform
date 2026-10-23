<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignMentorRequest;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\MentorAssignmentService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins assign or change a participant's mentor on the session dashboard's Mentors tab. The participant is bound
 * through the session's participants, so someone who is not in the session is not found.
 */
class AdminTrainingSessionMentorAssignmentController extends Controller
{
    public function update(AssignMentorRequest $request, TrainingSession $trainingSession, User $participant, MentorAssignmentService $mentorAssignments): RedirectResponse
    {
        $mentor = $request->mentor();
        $assignment = $mentorAssignments->assign($trainingSession, $participant, $mentor, $request->user());

        $status = $assignment->wasRecentlyCreated
            ? "{$mentor->name} now mentors {$participant->name}."
            : "{$mentor->name} already mentors {$participant->name}.";

        return redirect()->to(route('admin.sessions.show', $trainingSession).'#mentors')->with('status', $status);
    }
}
