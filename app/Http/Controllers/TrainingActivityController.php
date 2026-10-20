<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\SubmissionWorkflowService;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingActivityController extends Controller
{
    public function show(Request $request, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, SubmissionWorkflowService $workflow, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $readiness): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->reconcileStale($user->id);
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        if ($progression->requiresAttendanceForNewActivity($user, $trainingWeek, $activity)) {
            return redirect()->route('dashboard')->withErrors(['attendance' => 'Please check in to access this week\'s activities.']);
        }
        $isValidationChecklist = $user->role === 'participant'
            && $trainingWeek->week_number === 2
            && $activity->position === 1
            && $activity->title === 'Validation Checklist';
        if ($isValidationChecklist && ! $user->validationSubmissions()->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->exists()) {
            return redirect()->route('lab.activity', ['continue' => 'validation-checklist'])
                ->with('status', 'Complete and submit a QA Validation Lab record before starting the Validation Checklist.');
        }
        $requirements = $activity->requirements()->when($user->role === 'participant', function ($query) use ($user): void {
            $query->where(function ($pathQuery) use ($user): void {
                $pathQuery->whereNull('participant_path')->orWhereIn('participant_path', $user->eligibleTrainingPaths());
            });
        })->with('evidenceRequirements')->orderBy('position')->get();
        if ($user->role === 'participant') {
            $requirements = $requirements->filter(fn ($requirement): bool => ! ($requirement->configuration['deprecated'] ?? false))->values();
        }
        $submission = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $user->id)->with(['versions' => fn ($query) => $query->with(['evidence', 'reviews', 'activityScore'])->orderByDesc('version_number')])->orderByDesc('attempt_number')->first();
        if ($user->role === 'participant' && $readiness->enforced() && ! $readiness->isReady($user) && ! $submission) {
            return redirect()->route('dashboard')->withErrors(['google_drive_setup' => $readiness->message($user)]);
        }
        $attempts = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $user->id)->with(['versions' => fn ($query) => $query->with(['reviews', 'activityScore'])->orderByDesc('version_number')])->orderBy('attempt_number')->get();
        $latestVersion = $submission?->versions->first();
        $recoveryRequests = ActivityRecoveryRequest::whereIn('activity_submission_id', $attempts->pluck('id'))->latest()->get();
        $labRecords = $activity->configuration['lab_route'] ?? null
            ? ValidationSubmission::query()->where('user_id', $user->id)->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->latest('submitted_at')->latest('id')->get()
            : collect();
        $labPrerequisiteMet = ! ($user->role === 'participant' && $trainingWeek->week_number === 2 && $activity->position === 1 && $activity->title === 'Validation Checklist')
            || $labRecords->isNotEmpty();
        $driveExport = GoogleDriveExport::query()->where('user_id', $user->id)->where('export_type', 'activity')->where('scope_key', (string) $activity->id)->first();
        $evidenceIds = $latestVersion?->evidence->pluck('id') ?? collect();
        $evidenceExports = GoogleDriveEvidenceExport::query()->whereIn('submission_evidence_id', $evidenceIds)->get()->keyBy('submission_evidence_id');

        return view('activities.show', ['activity' => $activity, 'trainingWeek' => $trainingWeek, 'requirements' => $requirements, 'evidenceRequirements' => $workflow->evidenceRequirements($activity, $user), 'submission' => $submission, 'attempts' => $attempts, 'recoveryRequests' => $recoveryRequests, 'latestVersion' => $latestVersion, 'user' => $user, 'labRecords' => $labRecords, 'labPrerequisiteMet' => $labPrerequisiteMet, 'driveExport' => $driveExport, 'evidenceExports' => $evidenceExports]);
    }
}
