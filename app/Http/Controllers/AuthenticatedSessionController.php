<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAuthenticatedSessionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(StoreAuthenticatedSessionRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        if (! Auth::attempt(array_merge($credentials, ['is_active' => true]), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();
        // A normal login always starts at the newly authenticated user's role landing page.
        // Any intended URL was created before authentication and may belong to another account.
        $request->session()->forget(['url.intended', 'last_page', 'redirect_to', 'intended_route', 'return_url']);

        $destination = match ($request->user()->role) {
            'admin' => route('admin.dashboard', absolute: false),
            'coach' => route('coach.dashboard', absolute: false),
            default => route('dashboard', absolute: false),
        };

        return redirect()->to($destination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
