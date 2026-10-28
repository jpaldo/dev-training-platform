<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProgramRepositoryRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\ParticipantProgramPageService;
use App\Services\ProgramEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TrainingProgramController extends Controller
{
    /**
     * The curriculum details page for a participant in the curriculum. The route has already checked that the user is a
     * participant who can view the curriculum.
     */
    public function show(Request $request, Program $program, ParticipantProgramPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('training-programs.show', $page->showData($program->loadScheduleBounds(), $user));
    }

    /**
     * Join the curriculum in one click and open its page. Joining again (a double click, or a second tab) changes
     * nothing and opens the page too; everyone else must pass the `join` policy.
     */
    public function join(Request $request, Program $program, ProgramEnrollmentService $enrollments): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($program->includesParticipant($user)) {
            return redirect()->route('training.programs.show', $program)
                ->with('status', "You are already in {$program->name}.");
        }

        Gate::authorize('join', $program);

        $enrollments->join($program, $user);

        return redirect()->route('training.programs.show', $program)
            ->with('status', "You joined {$program->name}.");
    }

    /**
     * Save the participant's GitHub repository for the curriculum and return to the curriculum page. Only a participant
     * in the curriculum gets here (UpdateProgramRepositoryRequest).
     */
    public function updateRepository(UpdateProgramRepositoryRequest $request, Program $program, ProgramEnrollmentService $enrollments): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $enrollments->saveRepositoryUrl($program, $user, $request->validated('repository_url'));

        return redirect()->route('training.programs.show', $program)
            ->with('status', 'Your GitHub repository is saved.');
    }
}
