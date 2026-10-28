<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProgramMentorRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\MentorAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminProgramMentorController extends Controller
{
    public function store(StoreProgramMentorRequest $request, Program $program, MentorAssignmentService $mentorAssignments): RedirectResponse
    {
        $addedCount = $mentorAssignments->addMentors($program, $request->mentorIds(), $request->user());

        return $this->toMentorsTab($program)
            ->with('status', 'Added '.$addedCount.' '.Str::plural('mentor', $addedCount).'.');
    }

    /**
     * The mentor is bound through the curriculum's mentors, so someone who is not a mentor of the curriculum is not
     * found. Their current participants in the curriculum become unassigned.
     */
    public function destroy(Request $request, Program $program, User $mentor, MentorAssignmentService $mentorAssignments): RedirectResponse
    {
        Gate::authorize('manage', $program);

        $mentorAssignments->removeMentor($program, $mentor, $request->user());

        return $this->toMentorsTab($program)
            ->with('status', "Removed {$mentor->name} as a mentor of the curriculum.");
    }

    private function toMentorsTab(Program $program): RedirectResponse
    {
        return redirect()->to(route('admin.programs.show', $program).'#mentors');
    }
}
