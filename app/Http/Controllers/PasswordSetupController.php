<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupPasswordRequest;
use App\Services\PasswordSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PasswordSetupController extends Controller
{
    public function __construct(private readonly PasswordSetupService $passwordSetup) {}

    public function create(string $token): View
    {
        $invite = $this->passwordSetup->findValid($token);

        abort_unless($invite, 404);

        return view('auth.password-setup', ['token' => $token, 'user' => $invite->user]);
    }

    public function store(SetupPasswordRequest $request, string $token): RedirectResponse
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 400, 'Password setup requires HTTPS.');

        $this->passwordSetup->complete($token, $request->validated('password'));

        return redirect()->route('login')->with('status', 'Your password has been set. You can now sign in.');
    }
}
