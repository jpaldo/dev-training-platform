<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendEmailTestRequest;
use App\Http\Requests\UpdateCalendarAvailabilityRequest;
use App\Http\Requests\UpdateEmailSettingsRequest;
use App\Models\User;
use App\Services\AdminSettingsPageService;
use App\Services\EmailConfigurationService;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class AdminSettingsController extends Controller
{
    public function index(AdminSettingsPageService $page): View
    {
        return view('admin.settings.index', $page->indexData());
    }

    public function google(AdminSettingsPageService $page): View
    {
        return view('admin.settings.google', $page->googleData());
    }

    public function updateCalendarAvailability(UpdateCalendarAvailabilityRequest $request, GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        $configuration->saveParticipantCalendarAvailability((bool) $request->validated('enabled'));

        return back()->with('status', $request->boolean('enabled')
            ? 'Participant Calendar / Google Meet scheduling enabled.'
            : 'Participant Calendar / Google Meet scheduling disabled. Existing Drive connections and scheduled interviews were preserved.');
    }

    public function verificationConnect(Request $request, GoogleDriveConfigurationService $configuration, GoogleDriveConnectionService $google): RedirectResponse
    {
        if (! $configuration->isOAuthConfigured()) {
            return back()->withErrors(['google_verification' => 'Complete the Google OAuth configuration before starting verification.']);
        }

        $state = $google->newState();
        $request->session()->put('google_verification_oauth', [
            'state' => $state,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away($google->authorizationUrl($state, true));
    }

    public function verificationCallback(Request $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $pending = $request->session()->pull('google_verification_oauth');
        $state = (string) $request->query('state', '');
        if (! is_array($pending) || blank($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state) || (int) ($pending['user_id'] ?? 0) !== $admin->id || (int) ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return redirect()->route('admin.settings.google')->withErrors(['google_verification' => 'Google verification authorization could not be verified. Please try again.']);
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

    public function email(AdminSettingsPageService $page): View
    {
        return view('admin.settings.email', $page->emailData());
    }

    public function updateEmail(UpdateEmailSettingsRequest $request, EmailConfigurationService $email): RedirectResponse
    {
        $email->save($request->validated());

        return back()->with('status', 'Email configuration saved securely.');
    }

    public function sendTestEmail(SendEmailTestRequest $request, EmailConfigurationService $email): RedirectResponse
    {
        $email->applyRuntimeConfiguration();

        try {
            Mail::raw('This is a Developer Training Platform email configuration test.', function (Message $message) use ($request): void {
                $message->to($request->string('recipient')->toString())->subject('Developer Training Platform email test');
            });
        } catch (Throwable $exception) {
            Log::warning('Admin email configuration test failed.', ['exception' => $exception::class]);

            return back()->withErrors(['email_test' => 'Unable to send the test email. Please verify the email configuration.']);
        }

        return back()->with('status', 'Test email sent successfully to '.$request->string('recipient')->toString().'.');
    }

    public function training(AdminSettingsPageService $page): View
    {
        return view('admin.settings.training', $page->trainingData());
    }
}
