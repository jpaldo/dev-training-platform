<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProgramParticipantsRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\ProgramEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminProgramParticipantController extends Controller
{
    public function store(StoreProgramParticipantsRequest $request, Program $program, ProgramEnrollmentService $enrollments): RedirectResponse
    {
        $addedCount = $enrollments->addParticipants($program, $request->participantIds(), $request->user());

        return $this->toParticipantsTab($program)
            ->with('status', 'Added '.$addedCount.' '.Str::plural('participant', $addedCount).'.');
    }

    /**
     * The participant is bound through the curriculum's participants, so someone who is not in the curriculum is not
     * found.
     */
    public function destroy(Request $request, Program $program, User $participant, ProgramEnrollmentService $enrollments): RedirectResponse
    {
        Gate::authorize('manage', $program);

        $enrollments->removeParticipant($program, $participant, $request->user());

        return $this->toParticipantsTab($program)
            ->with('status', "Removed {$participant->name} from the curriculum.");
    }

    private function toParticipantsTab(Program $program): RedirectResponse
    {
        return redirect()->to(route('admin.programs.show', $program).'#participants');
    }
}
