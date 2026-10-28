<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreSubmissionDraftRequest;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ActivitySubmissionPageService;
use App\Services\ActivitySubmissionService;
use App\Services\GoogleDriveReadinessService;
use App\Services\WeekProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ActivitySubmissionController extends Controller
{
    /**
     * A handed-in version of the participant's attempt at the activity; a draft, or a version of someone else's attempt
     * or of an attempt at another activity, is a 404, and a version of a week that is not open for the participant is a
     * 403 with the week's locked message.
     */
    public function show(Request $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $submissionVersion, WeekProgressionService $progression, ActivitySubmissionPageService $pages): View
    {
        /** @var User $user */
        $user = $request->user();
        $submissionVersion->load(['submission.participant', 'submission.activity', 'submission.versions', 'evidence', 'reviews', 'publishedActivityScore.dimensions', 'interactions']);
        $submission = $submissionVersion->submission;
        abort_unless($submission->user_id === $user->id && $submission->isForActivity($activity) && $submission->activity->training_week_id === $trainingWeek->id, 404);
        abort_unless(! $submissionVersion->isDraft(), 404);
        $progression->ensureCanAccessWeek($user, $program, $trainingWeek);

        return view('submissions.show', [
            'program' => $program,
            'trainingWeek' => $trainingWeek,
            'activity' => $activity,
            'submissionVersion' => $submissionVersion,
            ...$pages->showData($activity, $submissionVersion),
        ]);
    }

    public function edit(Request $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, GoogleDriveReadinessService $readiness, ActivitySubmissionPageService $pages): View|Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasAnyRole(UserRole::Participant) && $readiness->enforced() && ! $readiness->isReady($user)) {
            return redirect()->route('dashboard')->withErrors(['google_drive_setup' => $readiness->message($user)]);
        }
        $progression->ensureCanAccessActivity($user, $program, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $program, $activity);

        return response()
            ->view('submissions.edit', $pages->editData($user, $program, $trainingWeek, $activity))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function storeDraft(StoreSubmissionDraftRequest $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, GoogleDriveReadinessService $readiness, ActivitySubmissionService $submissions): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureCanEdit($user, $program, $trainingWeek, $activity, $progression, $readiness);
        Gate::authorize('create', ActivitySubmission::class);
        $version = $submissions->saveDraft($user, $activity, $request->validated('field_values', []), $request->validated('participant_note'), $request->validated('interactions'));

        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'version_id' => $version->id, 'status' => 'Saved']);
        }

        return redirect()->route('training.submission.edit', [$program, $trainingWeek, $activity])->with('status', 'Draft saved.');
    }

    public function revise(Request $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, GoogleDriveReadinessService $readiness, ActivitySubmissionService $submissions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureCanEdit($user, $program, $trainingWeek, $activity, $progression, $readiness);
        $submission = $submissions->latestAttempt($user, $activity);
        if ($submissions->hasDraftVersion($submission)) {
            return redirect()->route('training.submission.edit', [$program, $trainingWeek, $activity])->with('status', 'Revision draft already available.');
        }
        abort_unless($submission->needsRevision(), 422);
        $submissions->createRevisionDraft($user, $activity, $submission);

        return redirect()->route('training.submission.edit', [$program, $trainingWeek, $activity])->with('status', 'Revision draft created.');
    }

    public function submit(Request $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $submissionVersion, WeekProgressionService $progression, GoogleDriveReadinessService $readiness, ActivitySubmissionService $submissions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureCanEdit($user, $program, $trainingWeek, $activity, $progression, $readiness);
        abort_unless($submissionVersion->submission->isForActivity($activity), 404);
        Gate::authorize('update', $submissionVersion);
        $submissions->submit($user, $submissionVersion);

        return redirect()->route('training.submission.edit', [$program, $trainingWeek, $activity])->with('status', 'Submission sent for review.');
    }

    /**
     * Stop the write when Google Drive is not ready (403) or the activity cannot be accessed or edited.
     */
    private function ensureCanEdit(User $user, Program $program, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, GoogleDriveReadinessService $readiness): void
    {
        abort_if($user->hasAnyRole(UserRole::Participant) && $readiness->enforced() && ! $readiness->isReady($user), 403, $readiness->message($user));
        $progression->ensureCanAccessActivity($user, $program, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $program, $activity);
    }
}
