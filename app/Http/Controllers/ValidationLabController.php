<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreValidationArtifactRequest;
use App\Http\Requests\StoreValidationSubmissionRequest;
use App\Http\Requests\VerifyLabOtpRequest;
use App\Models\ValidationSubmission;
use App\Services\ValidationLabService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

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
            if ($user->hasAnyRole(['participant'])) {
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

    public function artifact(StoreValidationArtifactRequest $request, ValidationLabService $lab): RedirectResponse|View
    {
        $data = $request->validated();
        $isLegacyArtifact = $request->isLegacyArtifact();
        if (! $isLegacyArtifact) {
            $data = $lab->resolveArtifactInput($data);
            $blankInputErrors = $lab->blankArtifactInputErrors($data);
            if ($blankInputErrors !== []) {
                return back()->withErrors($blankInputErrors)->withInput();
            }
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

    public function submitRecord(Request $request, ValidationSubmission $submission, ValidationLabService $lab): RedirectResponse
    {
        abort_unless((int) $submission->user_id === (int) $request->user()->id, 403);
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
        $user = $request->user();
        $isOwner = $submission->user_id !== null && (int) $submission->user_id === (int) $user->id;
        $isAuthorizedCoachLink = $user->isCoachOrAdmin() && $lab->isLinkedToParticipantSubmission($submission, $request->integer('submission_version'));
        abort_unless($isOwner || $isAuthorizedCoachLink, 403);

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
