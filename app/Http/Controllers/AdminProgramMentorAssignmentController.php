<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignMentorRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\MentorAssignmentService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins assign or change a participant's mentor on the curriculum page's Mentors tab. The participant is bound
 * through the curriculum's participants, so someone who is not in the curriculum is not found.
 */
class AdminProgramMentorAssignmentController extends Controller
{
    public function update(AssignMentorRequest $request, Program $program, User $participant, MentorAssignmentService $mentorAssignments): RedirectResponse
    {
        $mentor = $request->mentor();
        $assignment = $mentorAssignments->assign($program, $participant, $mentor, $request->user());

        $status = $assignment->wasRecentlyCreated
            ? "{$mentor->name} now mentors {$participant->name}."
            : "{$mentor->name} already mentors {$participant->name}.";

        return redirect()->to(route('admin.programs.show', $program).'#mentors')->with('status', $status);
    }
}
