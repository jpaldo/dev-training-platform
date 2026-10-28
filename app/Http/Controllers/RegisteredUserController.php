<?php

namespace App\Http\Controllers;

use App\Enums\JobRole;
use App\Enums\UserRole;
use App\Http\Requests\StoreRegisteredUserRequest;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\User;
use App\Services\ProgramEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(ProgramEnrollmentService $enrollments): View
    {
        return view('auth.register', [
            'jobRoleOptions' => JobRole::options(),
            'programChoices' => $enrollments->registrationChoices(),
        ]);
    }

    /**
     * Create the participant and join the curricula they ticked, all or nothing, then sign them in.
     */
    public function store(StoreRegisteredUserRequest $request, ProgramEnrollmentService $enrollments): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $enrollments): User {
            $user = User::create([...$request->userAttributes(), 'role' => UserRole::Participant]);

            $request->programsToJoin()->each(
                fn (Program $program): ProgramParticipant => $enrollments->join($program, $user),
            );

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile.show');
    }
}
