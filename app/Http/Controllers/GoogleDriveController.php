<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ConnectGoogleDriveRequest;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleDriveController extends Controller
{
    /**
     * Send the participant to Google's consent screen, remembering the training page to return to (if any).
     */
    public function connect(ConnectGoogleDriveRequest $request, GoogleDriveConnectionService $google, GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        if (! $google->enabled()) {
            return redirect()->route('profile.show')->withErrors(['google_drive' => 'Google Drive connection is not configured yet.']);
        }

        $state = $google->newState();
        $request->session()->put('google_drive_oauth', [
            'state' => $state,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
            'return_to' => $request->returnTo(),
        ]);

        return redirect()->away($google->authorizationUrl($state));
    }

    /**
     * Finish the connection and return to the page it was started from (the profile unless `connect()` kept a
     * training page), with the outcome flashed.
     */
    public function callback(Request $request, GoogleDriveConnectionService $google, GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        if ($request->user()?->role !== UserRole::Participant) {
            return redirect()->route('login')->withErrors(['google_drive' => 'Start Google authorization from the participant Google Drive profile page.']);
        }

        $pending = $request->session()->pull('google_drive_oauth');
        $returnUrl = $this->returnUrl($pending);
        $state = (string) $request->query('state', '');
        if (! is_array($pending) || blank($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state) || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id || (int) ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return redirect()->to($returnUrl)->withErrors(['google_drive' => 'Google Drive authorization could not be verified. Please try again.']);
        }

        if ($request->query('error') === 'access_denied') {
            return redirect()->to($returnUrl)->with('status', 'Google Drive connection was cancelled.');
        }
        if ($request->query('error') || blank($request->query('code'))) {
            return redirect()->to($returnUrl)->withErrors(['google_drive' => 'Unable to connect Google Drive. Please try again.']);
        }

        try {
            $google->connect($request->user(), (string) $request->query('code'));
        } catch (Throwable $exception) {
            Log::warning('Google Drive connection failed.', ['exception' => $exception::class]);

            return redirect()->to($returnUrl)->withErrors(['google_drive' => 'Unable to connect Google Drive. Please try again.']);
        }

        return redirect()->to($returnUrl)->with('status', 'Google Drive connected successfully.');
    }

    public function disconnect(Request $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        $google->disconnect($request->user());

        return redirect()->route('profile.show')->with('status', 'Google Drive disconnected.');
    }

    /**
     * The training page `connect()` stored in the pending authorization (already checked by
     * ConnectGoogleDriveRequest::returnTo()), or the profile.
     */
    private function returnUrl(mixed $pending): string
    {
        $returnTo = is_array($pending) ? ($pending['return_to'] ?? null) : null;

        return is_string($returnTo) ? $returnTo : route('profile.show');
    }
}
