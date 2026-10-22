<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleDriveController extends Controller
{
    public function connect(Request $request, GoogleDriveConnectionService $google, GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        if (! $google->enabled()) {
            return redirect()->route('profile.show')->withErrors(['google_drive' => 'Google Drive connection is not configured yet.']);
        }

        $state = $google->newState();
        $request->session()->put('google_drive_oauth', [
            'state' => $state,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away($google->authorizationUrl($state, false));
    }

    public function mentorConnect(Request $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        abort_unless($request->user()->isCoachOrAdmin(), 403);
        $state = $google->newState();
        $request->session()->put('mentor_google_oauth', ['state' => $state, 'user_id' => $request->user()->id, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return redirect()->away($google->calendarAuthorizationUrl($state));
    }

    public function callback(Request $request, GoogleDriveConnectionService $google, GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        if ($this->isVerificationCallback($request)) {
            return $this->completeVerificationCallback($request, $google);
        }
        if ($this->isMentorCallback($request)) {
            return $this->completeMentorCallback($request, $google);
        }

        if ($request->user()?->role !== UserRole::Participant) {
            return redirect()->route('login')->withErrors(['google_drive' => 'Start Google authorization from the participant Google Drive profile page.']);
        }

        $pending = $request->session()->pull('google_drive_oauth');
        $state = (string) $request->query('state', '');
        if (! is_array($pending) || blank($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state) || (int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id || (int) ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return redirect()->route('profile.show')->withErrors(['google_drive' => 'Google Drive authorization could not be verified. Please try again.']);
        }

        if ($request->query('error') === 'access_denied') {
            return redirect()->route('profile.show')->with('status', 'Google Drive connection was cancelled.');
        }
        if ($request->query('error') || blank($request->query('code'))) {
            return redirect()->route('profile.show')->withErrors(['google_drive' => 'Unable to connect Google Drive. Please try again.']);
        }

        try {
            $google->connect($request->user(), (string) $request->query('code'), false);
        } catch (Throwable $exception) {
            Log::warning('Google Drive connection failed.', ['exception' => $exception::class]);

            return redirect()->route('profile.show')->withErrors(['google_drive' => 'Unable to connect Google Drive. Please try again.']);
        }

        return redirect()->route('profile.show')->with('status', 'Google Drive connected successfully.');
    }

    private function isMentorCallback(Request $request): bool
    {
        $pending = $request->session()->get('mentor_google_oauth');
        $state = (string) $request->query('state', '');

        return is_array($pending) && filled($state) && hash_equals((string) ($pending['state'] ?? ''), $state);
    }

    private function completeMentorCallback(Request $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        $pending = $request->session()->pull('mentor_google_oauth');
        $coach = $request->user();
        if (! is_array($pending) || ! $coach?->isCoachOrAdmin() || (int) ($pending['user_id'] ?? 0) !== (int) $coach?->id || (int) ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return redirect()->route('login')->withErrors(['google_calendar' => 'Google Calendar authorization could not be verified. Please start again.']);
        }
        if ($request->query('error') || blank($request->query('code'))) {
            return redirect()->route('coach.mentor-availability.index')->withErrors(['google_calendar' => 'Google Calendar authorization was not granted.']);
        }
        try {
            $google->connect($coach, (string) $request->query('code'), true);
        } catch (Throwable $exception) {
            Log::warning('Mentor Google Calendar OAuth failed.', ['exception' => $exception::class]);

            return redirect()->route('coach.mentor-availability.index')->withErrors(['google_calendar' => 'Google Calendar authorization could not be completed.']);
        }

        return redirect()->route('coach.mentor-availability.index')->with('status', 'Google Calendar connected for Mentor interviews.');
    }

    private function isVerificationCallback(Request $request): bool
    {
        $pending = $request->session()->get('google_verification_oauth');
        $state = (string) $request->query('state', '');

        return is_array($pending) && filled($state) && hash_equals((string) ($pending['state'] ?? ''), $state);
    }

    private function completeVerificationCallback(Request $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        $pending = $request->session()->pull('google_verification_oauth');
        $admin = $request->user();
        if (! is_array($pending) || $admin?->role !== UserRole::Admin || (int) ($pending['user_id'] ?? 0) !== (int) $admin?->id || (int) ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return redirect()->route('login')->withErrors(['google_verification' => 'Google verification authorization could not be verified. Please start again.']);
        }
        if ($request->query('error') === 'access_denied') {
            return redirect()->route('admin.settings.google')->with('status', 'Google Calendar verification authorization was cancelled.');
        }
        if ($request->query('error') || blank($request->query('code'))) {
            return redirect()->route('admin.settings.google')->withErrors(['google_verification' => 'Google Calendar verification authorization was not granted.']);
        }

        try {
            $google->connect($admin, (string) $request->query('code'), true);
        } catch (Throwable $exception) {
            Log::warning('Google verification OAuth failed.', ['exception' => $exception::class]);

            return redirect()->route('admin.settings.google')->withErrors(['google_verification' => 'Google Calendar verification authorization could not be completed.']);
        }

        return redirect()->route('admin.settings.google')->with('status', 'Google Calendar verification authorization completed.');
    }

    public function disconnect(Request $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        $google->disconnect($request->user());

        return redirect()->route('profile.show')->with('status', 'Google Drive disconnected.');
    }
}
