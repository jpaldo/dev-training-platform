<?php

namespace App\Http\Controllers;

use App\Enums\JobRole;
use App\Enums\UserRole;
use App\Http\Requests\StoreRegisteredUserRequest;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use App\Services\TrainingSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(TrainingSessionService $sessions): View
    {
        return view('auth.register', [
            'jobRoleOptions' => JobRole::options(),
            'trainingSessionChoices' => $sessions->registrationChoices(),
        ]);
    }

    /**
     * Create the participant and join the training sessions they ticked, all or nothing, then sign them in.
     */
    public function store(StoreRegisteredUserRequest $request, TrainingSessionService $sessions): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $sessions): User {
            $user = User::create([...$request->userAttributes(), 'role' => UserRole::Participant]);

            $request->trainingSessionsToJoin()->each(
                fn (TrainingSession $trainingSession): TrainingSessionParticipant => $sessions->join($trainingSession, $user),
            );

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile.show');
    }
}
