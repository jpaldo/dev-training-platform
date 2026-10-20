<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreValidationArtifactRequest;
use App\Http\Requests\StoreValidationSubmissionRequest;
use App\Http\Requests\VerifyLabOtpRequest;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\ValidationSubmission;
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

    public function labHome(Request $request): RedirectResponse|View
    {
        if (Auth::check()) {
            session(['trainee_name' => Auth::user()->name]);
            if (Auth::user()->role === 'participant' && $request->query('continue') === 'validation-checklist') {
                session(['lab_continue_to' => 'validation-checklist']);
            }
            if (Auth::user()->role === 'participant') {
                $draft = Auth::user()->validationSubmissions()->where('status', ValidationSubmission::DRAFT)->latest('updated_at')->first();
                if ($draft) {
                    return redirect()->route('result', $draft)->with('status', 'Continue your saved QA Validation Lab record, then submit it to unlock the Validation Checklist.');
                }
            }
        }

        return view('lab.activity');
    }

    public function artifact(StoreValidationArtifactRequest $request): RedirectResponse|View
    {
        $data = $request->validated();
        $legacy = $request->isLegacyArtifact();
        if (! $legacy) {
            $data['artifact_name'] = trim((string) $data['artifact_name']);
            if ($data['feature_area'] === 'Other') {
                $data['feature_area'] = trim((string) ($data['other_feature_area'] ?? ''));
                if ($data['feature_area'] === '') {
                    return back()->withErrors(['other_feature_area' => 'Please enter the feature or area.'])->withInput();
                }
            }
            if ($data['artifact_type'] === 'Other') {
                $data['artifact_type'] = trim((string) ($data['other_artifact_type'] ?? ''));
                if ($data['artifact_type'] === '') {
                    return back()->withErrors(['other_artifact_type' => 'Please enter the artifact type.'])->withInput();
                }
            }
            if ($data['artifact_name'] === '') {
                return back()->withErrors(['artifact_name' => 'Artifact name is required.'])->withInput();
            }
        }
        unset($data['other_feature_area'], $data['other_artifact_type']);
        $data = array_merge(config('validation_lab.default_artifact'), [
            'ai_tool' => config('validation_lab.default_ai_tool'),
            'artifact_content' => "TC-014 — Verify OTP expiration\n\nPrecondition: User has valid credentials.\nSteps:\n1. Open the login page.\n2. Enter valid email and password.\n3. Enter the OTP sent to the user.\n4. Wait 5 minutes.\n5. Enter the same OTP.\n\nExpected Result:\nThe OTP should still be valid after 5 minutes and the user should be authenticated.",
        ], $data);
        $data['artifact_code'] = $legacy ? 'AI-TC-014' : $this->nextArtifactCode();
        session(['trainee_name' => $data['trainee_name'], 'validation_artifact' => $data]);

        return view('artifact', ['trainee' => $data['trainee_name'], 'artifact' => $data]);
    }

    public function checklist(Request $request): View
    {
        abort_unless(session('trainee_name'), 403);

        return view('checklist', [
            'trainee' => session('trainee_name'),
            'artifact' => session('validation_artifact', [
                'artifact_code' => $request->query('artifact', 'AI-TC-014'),
                'artifact_name' => 'Verify OTP expiration',
                'feature_area' => 'User Login / OTP Verification',
                'artifact_type' => 'AI-generated test case',
                'ai_tool' => 'Claude',
                'artifact_content' => 'Historical artifact preview.',
            ]),
        ]);
    }

    public function submit(StoreValidationSubmissionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['trainee_name'] = session('trainee_name', 'Unknown trainee');
        $data['user_id'] = Auth::id();
        $selected = session('validation_artifact', []);
        if (isset($selected['artifact_code'])) {
            $data['artifact_code'] = $selected['artifact_code'];
        }
        foreach (['feature_area', 'artifact_type', 'ai_tool', 'artifact_content'] as $key) {
            if (array_key_exists($key, $selected)) {
                $data[$key] = $selected[$key];
            }
        }
        if (array_key_exists('prompt', $selected)) {
            $data['prompt'] = $selected['prompt'];
        }
        $files = [];
        foreach ($request->file('evidence_files', []) as $file) {
            $files[] = ['disk' => 'local', 'path' => $file->store('validation-evidence/'.(Auth::id() ?: 'guest'), 'local'), 'original_filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()];
        }
        $data['evidence_files'] = $files;
        // The six-check form's explicit "Submit Validation Record" action is
        // the Lab submission boundary. It is already fully validated above.
        $data['status'] = ValidationSubmission::SUBMITTED;
        $data['submitted_at'] = now();
        $submission = ValidationSubmission::create($data);

        return redirect()->route('result', $submission);
    }

    public function submitRecord(Request $request, ValidationSubmission $submission): RedirectResponse
    {
        abort_unless((int) $submission->user_id === (int) $request->user()->id, 403);
        abort_unless($submission->status === ValidationSubmission::DRAFT, 422);

        $requiredChecks = ['source', 'scope', 'spec', 'cross_check', 'run', 'log'];
        foreach ($requiredChecks as $check) {
            if (blank($submission->{$check.'_status'}) || blank($submission->{$check.'_notes'})) {
                return back()->withErrors(['submission' => 'Complete every six-check result and its notes before submitting the Lab record.']);
            }
        }
        if (blank($submission->finding) || blank($submission->decision) || blank($submission->evidence_notes)) {
            return back()->withErrors(['submission' => 'Complete the Lab finding, decision, and evidence record before submitting.']);
        }

        $submission->update(['status' => ValidationSubmission::SUBMITTED, 'submitted_at' => now()]);

        return redirect()->route('result', $submission)->with('status', 'Lab record submitted. You can now start Validation Checklist.');
    }

    private function nextArtifactCode(): string
    {
        $max = ValidationSubmission::query()->pluck('artifact_code')->map(fn (string $code): int => (int) preg_replace('/\D+/', '', (string) $code))->max() ?? 14;

        return 'AI-TC-'.str_pad((string) max(15, $max + 1), 3, '0', STR_PAD_LEFT);
    }

    public function result(ValidationSubmission $submission): View
    {
        $activityOneUrl = null;
        if (Auth::check() && Auth::user()->role === 'participant' && (int) $submission->user_id === (int) Auth::id() && $submission->isSubmitted()) {
            $week = Program::query()->where('is_active', true)->with('weeks.activities')->first()?->weeks->firstWhere('week_number', 2);
            $activity = $week?->activities->firstWhere('position', 1);
            if ($week && $activity) {
                $activityOneUrl = route('training.submission.edit', [$week, $activity]).'?validation_submission_id='.$submission->id;
            }
        }

        return view('result', compact('submission', 'activityOneUrl'));
    }

    public function ownedRecord(Request $request, ValidationSubmission $submission): View
    {
        $user = $request->user();
        $isOwner = $submission->user_id !== null && (int) $submission->user_id === (int) $user->id;
        $isAuthorizedCoachLink = false;
        if ($user->isCoachOrAdmin()) {
            $version = SubmissionVersion::query()->with('submission.participant')->find($request->integer('submission_version'));
            $isAuthorizedCoachLink = $version
                && (int) $version->validation_submission_id === (int) $submission->id
                && $submission->user_id !== null
                && $version->submission?->participant?->role === 'participant'
                && (int) $version->submission->user_id === (int) $submission->user_id;
        }
        abort_unless($isOwner || $isAuthorizedCoachLink, 403);

        return view('result', compact('submission'));
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
