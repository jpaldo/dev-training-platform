<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreValidationArtifactRequest;
use App\Http\Requests\StoreValidationSubmissionRequest;
use App\Http\Requests\VerifyLabOtpRequest;
use App\Models\ValidationSubmission;
use App\Services\ValidationLabService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The QA Validation Lab and its controlled mock application (`lab.login`, `lab.send-otp`, `lab.otp`, `lab.verify-otp`,
 * `lab.success`), which Week 2 participants test for defects.
 *
 * The mock application's defects are intentional training defects. Do not fix them in a cleanup; the list is kept
 * here, not in the Week 2 seeder guidance, because participants read that guidance and it would give the answers away:
 *
 * - The login Email and Password fields have no `name` or `id` and their labels no `for`, so `sendOtp()` accepts any
 *   (or no) credentials.
 * - `/lab/otp` and `GET /lab/success` open without signing in or verifying an OTP.
 * - "After 3 failed attempts, the account is temporarily blocked." is never enforced, nor is the 2-minute OTP
 *   validity window.
 * - Resend OTP only shows an alert; no new OTP is issued.
 * - The OTP input has no `id` and its label no `for`.
 * - Return to Checklist on the success page returns 403 without a `trainee_name` session.
 */
class ValidationLabController extends Controller
{
    public function index(): RedirectResponse|View
    {
        if (Auth::check()) {
            return Auth::user()->isCoachOrAdmin() ? redirect()->route('coach.dashboard') : redirect()->route('dashboard');
        }

        return view('home');
    }

    public function labHome(Request $request, ValidationLabService $lab): RedirectResponse|View
    {
        $user = $request->user();
        if ($user) {
            session(['trainee_name' => $user->name]);
            if ($user->hasAnyRole(UserRole::Participant)) {
                if ($request->query('continue') === 'validation-checklist') {
                    session(['lab_continue_to' => 'validation-checklist']);
                }
                $draft = $lab->latestDraft($user);
                if ($draft) {
                    return redirect()->route('result', $draft)->with('status', 'Continue your saved QA Validation Lab record, then submit it to unlock the Validation Checklist.');
                }
            }
        }

        return view('lab.activity', $lab->activityFormData($user));
    }

    public function artifact(StoreValidationArtifactRequest $request, ValidationLabService $lab): View
    {
        $data = $request->validated();
        $isLegacyArtifact = $request->isLegacyArtifact();
        if (! $isLegacyArtifact) {
            $data = $lab->resolveArtifactInput($data);
        }
        $artifact = $lab->startArtifact($data, $isLegacyArtifact);
        session(['trainee_name' => $artifact['trainee_name'], 'validation_artifact' => $artifact]);

        return view('artifact', ['trainee' => $artifact['trainee_name'], 'artifact' => $artifact]);
    }

    public function checklist(Request $request, ValidationLabService $lab): View
    {
        abort_unless(session('trainee_name'), 403);

        return view('checklist', [
            'trainee' => session('trainee_name'),
            'artifact' => session('validation_artifact', $lab->historicalArtifact($request->query('artifact', 'AI-TC-014'))),
            ...$lab->checklistFormData(),
        ]);
    }

    public function submit(StoreValidationSubmissionRequest $request, ValidationLabService $lab): RedirectResponse
    {
        $submission = $lab->submit(
            $request->validated(),
            session('trainee_name', 'Unknown trainee'),
            $request->user()?->id,
            session('validation_artifact', []),
            $request->file('evidence_files', []),
        );

        return redirect()->route('result', $submission);
    }

    public function submitRecord(ValidationSubmission $submission, ValidationLabService $lab): RedirectResponse
    {
        Gate::authorize('submit', $submission);
        abort_unless($submission->isDraft(), 422);

        $incompleteRecordError = $lab->incompleteRecordError($submission);
        if ($incompleteRecordError !== null) {
            return back()->withErrors(['submission' => $incompleteRecordError]);
        }
        $lab->markSubmitted($submission);

        return redirect()->route('result', $submission)->with('status', 'Lab record submitted. You can now start Validation Checklist.');
    }

    public function result(Request $request, ValidationSubmission $submission, ValidationLabService $lab): View
    {
        return view('result', [
            'submission' => $submission,
            'checks' => $lab->resultChecks($submission),
            'activityOneUrl' => $lab->activityOneUrl($submission, $request->user()),
        ]);
    }

    public function ownedRecord(Request $request, ValidationSubmission $submission, ValidationLabService $lab): View
    {
        Gate::authorize('view', [$submission, $request->integer('submission_version')]);

        return view('result', [
            'submission' => $submission,
            'checks' => $lab->resultChecks($submission),
            'activityOneUrl' => null,
        ]);
    }

    public function appLogin(): View
    {
        return view('lab.login');
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        session(['otp_sent' => true]);

        return redirect()->route('lab.otp');
    }

    public function otp(): View
    {
        return view('lab.otp');
    }

    public function verifyOtp(VerifyLabOtpRequest $request): RedirectResponse|View
    {
        if ($request->otp === '248163') {
            return view('lab.success');
        }

        return back()->withErrors(['otp' => 'Invalid OTP. After 3 failed attempts, the account is temporarily blocked.']);
    }
}
