<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreRegisteredUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(StoreRegisteredUserRequest $request): RedirectResponse
    {
        $user = User::create([...$request->validated(), 'role' => UserRole::Participant]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile.show');
    }
}
