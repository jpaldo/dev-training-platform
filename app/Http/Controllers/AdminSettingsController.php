<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendEmailTestRequest;
use App\Http\Requests\UpdateEmailSettingsRequest;
use App\Services\AdminSettingsPageService;
use App\Services\EmailConfigurationService;
use Illuminate\Http\RedirectResponse;
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
