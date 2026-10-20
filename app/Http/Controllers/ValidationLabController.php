<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\ValidationSubmission;
use App\Services\EvidenceFilePolicy;
use App\Support\FieldLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ValidationLabController extends Controller
{
    public function index()
    {
        if (Auth::check()) {
            return Auth::user()->isCoachOrAdmin() ? redirect()->route('coach.dashboard') : redirect()->route('dashboard');
        }

        return view('home');
    }

    public function labHome(Request $request)
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

    public function artifact(Request $request)
    {
        $data = $request->validate([
            'trainee_name' => ['required', 'string', 'max:100'],
            'feature_area' => ['nullable', 'string', 'max:160'],
            'other_feature_area' => ['nullable', 'string', 'max:160'],
            'artifact_type' => ['nullable', 'string', 'max:120'],
            'other_artifact_type' => ['nullable', 'string', 'max:120'],
            'ai_tool' => ['nullable', 'string', 'max:120'],
            'prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'artifact_name' => ['nullable', 'string', 'max:160'],
            'artifact_content' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'evidence_files' => ['nullable', 'array', 'max:10'],
            'evidence_files.*' => app(EvidenceFilePolicy::class)->generalRules(),
        ]);
        $legacy = blank($data['feature_area'] ?? null) && blank($data['artifact_content'] ?? null);
        if (! $legacy) {
            $request->validate([
                'feature_area' => ['required', 'string', Rule::in(config('validation_lab.feature_areas'))],
                'other_feature_area' => ['required_if:feature_area,Other', 'nullable', 'string', 'max:160'],
                'artifact_type' => ['required', 'string', Rule::in(config('validation_lab.artifact_types'))],
                'other_artifact_type' => ['required_if:artifact_type,Other', 'nullable', 'string', 'max:120'],
                'ai_tool' => ['required', 'string', 'max:120'],
                'artifact_name' => ['required', 'string', 'max:160'],
                'artifact_content' => ['required', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
                'prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            ]);
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

    public function checklist(Request $request)
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

    public function submit(Request $request)
    {
        $request->merge(['artifact_name' => is_string($request->input('artifact_name')) ? trim($request->input('artifact_name')) : $request->input('artifact_name')]);
        $data = $request->validate([
            'artifact_code' => ['required', 'string'],
            'artifact_name' => ['required', 'string', 'max:160'],
            'feature_area' => ['nullable', 'string', 'max:160'],
            'artifact_type' => ['nullable', 'string', 'max:120'],
            'ai_tool' => ['nullable', 'string', 'max:120'],
            'prompt' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'artifact_content' => ['nullable', 'string', 'max:'.FieldLimits::EXACT_PROMPT_OUTPUT],
            'evidence_files' => ['nullable', 'array', 'max:10'],
            'evidence_files.*' => app(EvidenceFilePolicy::class)->generalRules(),
            'source_status' => ['required', 'in:pass,issue'],
            'source_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'scope_status' => ['required', 'in:pass,issue'],
            'scope_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'spec_status' => ['required', 'in:pass,issue'],
            'spec_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'cross_check_status' => ['required', 'in:pass,issue'],
            'cross_check_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'run_status' => ['required', 'in:pass,issue'],
            'run_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'log_status' => ['required', 'in:pass,issue'],
            'log_notes' => ['required', 'string', 'max:'.FieldLimits::CHECK_NOTES],
            'finding' => ['required', 'string', 'max:'.FieldLimits::FINDING],
            'decision' => ['required', 'in:accept,revise,reject,reprompt'],
            'evidence_notes' => ['required', 'string', 'max:'.FieldLimits::EVIDENCE],
        ]);
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

    public function submitRecord(Request $request, ValidationSubmission $submission)
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
        $max = ValidationSubmission::query()->pluck('artifact_code')->map(fn ($code) => (int) preg_replace('/\D+/', '', (string) $code))->max() ?? 14;

        return 'AI-TC-'.str_pad((string) max(15, $max + 1), 3, '0', STR_PAD_LEFT);
    }

    public function result(ValidationSubmission $submission)
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

    public function ownedRecord(Request $request, ValidationSubmission $submission)
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

    public function appLogin()
    {
        return view('lab.login');
    }

    public function sendOtp(Request $request)
    {
        session(['otp_sent' => true]);

        return redirect()->route('lab.otp');
    }

    public function otp()
    {
        return view('lab.otp');
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['otp' => ['required', 'digits:6']]);
        if ($request->otp === '248163') {
            return view('lab.success');
        }

        return back()->withErrors(['otp' => 'Invalid OTP. After 3 failed attempts, the account is temporarily blocked.']);
    }
}
