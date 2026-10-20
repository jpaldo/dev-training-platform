<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionDraftRequest;
use App\Jobs\ExportActivityJob;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveReadinessService;
use App\Services\MentorInterviewSchedulingService;
use App\Services\SubmissionAnswerPresenter;
use App\Services\SubmissionWorkflowService;
use App\Services\TrainingSyncCoordinator;
use App\Services\WeekFourInterviewService;
use App\Services\WeekFourReviewPresenter;
use App\Services\WeekProgressionService;
use App\Services\WeekThreeWorkspaceService;
use App\Support\Markdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActivitySubmissionController extends Controller
{
    public function show(Request $request, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $submissionVersion, SubmissionWorkflowService $workflow, SubmissionAnswerPresenter $answers, WeekFourReviewPresenter $weekFour): View
    {
        /** @var User $user */
        $user = $request->user();
        $submissionVersion->load(['submission.participant', 'submission.activity.week', 'submission.versions', 'evidence', 'reviews.reviewer', 'activityScore.dimensions', 'validationSubmission', 'interactions']);
        abort_unless($submissionVersion->submission->user_id === $user->id && $submissionVersion->submission->activity_id === $activity->id && $submissionVersion->submission->activity->training_week_id === $trainingWeek->id, 404);
        abort_unless($submissionVersion->status !== 'draft', 404);

        $configured = collect($workflow->fieldDefinitions($activity, $user))->mapWithKeys(fn ($field): array => [$field['key'] => $field['label']])->all();
        $legacyLabels = ['thread_drive_link' => 'Thread / Drive Link', 'ai_tool' => 'AI Tool', 'artifact_type' => 'Artifact Type', 'prompt' => 'Prompt', 'exact_ai_output' => 'Exact AI Output', 'ai_output' => 'AI Output', 'pre_acceptance_question' => 'Pre-acceptance Question', 'validation_performed' => 'Validation Performed'];
        $responses = collect($submissionVersion->field_values ?? [])->except(['week_three_snapshot'])->map(fn ($value, $key): array => [
            'label' => $configured[$key] ?? $legacyLabels[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'value' => $value,
            'markdown' => in_array($key, Markdown::OUTPUT_FIELDS, true),
        ]);
        $labRecord = $submissionVersion->validationSubmission;
        if ($labRecord && $labRecord->user_id !== $user->id) {
            $labRecord = null;
        }

        $finalScore = $submissionVersion->activityScore?->status === 'published'
            ? $submissionVersion->activityScore
            : null;
        $finalDimensions = $finalScore?->dimensions?->keyBy('dimension') ?? collect();
        $weekThreeSnapshot = $trainingWeek->week_number === 3 ? ($submissionVersion->field_values['week_three_snapshot'] ?? null) : null;
        $weekThreeReview = $weekThreeSnapshot ? $answers->weekThreeReview($weekThreeSnapshot, (int) $activity->position, $submissionVersion->submission->participant->training_path) : null;
        $weekFourReview = $weekFour->present($activity, $submissionVersion->field_values ?? []);

        return view('submissions.show', compact('trainingWeek', 'activity', 'submissionVersion', 'responses', 'labRecord', 'finalScore', 'finalDimensions', 'weekThreeReview', 'weekFourReview'));
    }

    public function edit(Request $request, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, SubmissionWorkflowService $workflow, GoogleDriveReadinessService $readiness, WeekThreeWorkspaceService $weekThree, WeekFourInterviewService $interviews, MentorInterviewSchedulingService $mentorScheduling, GoogleDriveConfigurationService $googleConfiguration): View|Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->role === 'participant' && $readiness->enforced() && ! $readiness->isReady($user)) {
            return redirect()->route('dashboard')->withErrors(['google_drive_setup' => $readiness->message($user)]);
        }
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        if ($user->role === 'participant' && $progression->requiresAttendanceForActivity($user, $trainingWeek, $activity)) {
            session()->put('attendance_return_to', route('training.submission.edit', [$trainingWeek, $activity]));

            return redirect()->route('participant.attendance.index')->withErrors([
                'attendance' => 'Please check in before continuing this activity.',
            ]);
        }
        $progression->ensureCanEditActivity($user, $trainingWeek, $activity);
        if ($user->role === 'participant' && $this->isValidationChecklist($trainingWeek, $activity) && ! $this->hasSubmittedLabRecord($user)) {
            return redirect()->route('training.activities.show', [$trainingWeek, $activity])->withErrors(['lab_prerequisite' => 'Submit a QA Validation Lab record before starting this activity.']);
        }
        $submission = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $user->id)->with(['versions' => fn ($query) => $query->with(['evidence', 'reviews', 'validationSubmission', 'interactions'])->orderByDesc('version_number')])->orderByDesc('attempt_number')->first();
        $version = $submission?->versions->first();

        $fields = $workflow->fieldDefinitions($activity, $user);
        // Always query the current owner-scoped Lab records on each form request.
        // Do not derive this list from a draft, session, or activity snapshot.
        $labRecords = array_key_exists('validation_submission_id', $fields)
            ? ValidationSubmission::query()->where('user_id', $user->id)->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->latest('submitted_at')->latest('id')->get()
            : collect();
        $requestedLabRecord = request()->integer('validation_submission_id');
        $selectedLabRecord = $version?->validation_submission_id
            ? $labRecords->firstWhere('id', $version->validation_submission_id)
            : ($requestedLabRecord ? $labRecords->firstWhere('id', $requestedLabRecord) : $this->carryForwardLabRecord($activity, $user, $submission, $labRecords));

        $weekThreeWorkspace = $trainingWeek->week_number === 3 ? $weekThree->workspace($user, $trainingWeek)->load(['findings.tests.fixValidation']) : null;
        $interviewPairs = $trainingWeek->week_number === 4 && $activity->position === 2 ? $interviews->pairsFor($user) : collect();
        $interviewPair = $interviewPairs->first();
        $interviewPartner = $interviewPair?->partnerFor($user->id);
        $interviewPartners = $interviewPairs->map(fn ($pair) => $pair->partnerFor($user->id))->filter()->values();
        $feedbackGiven = $interviewPair?->feedback()->where('author_id', $user->id)->get()->keyBy('question_number') ?? collect();
        $feedbackReceived = $interviewPair?->feedback()->where('recipient_id', $user->id)->get()->keyBy('question_number') ?? collect();
        $partnerInterview = $interviewPartner?->activitySubmissions()->where('activity_id', $activity->id)->with(['versions' => fn ($query) => $query->whereIn('status', ['submitted', 'resubmitted', 'approved', 'completed'])->orderByDesc('version_number')])->latest('attempt_number')->first()?->versions->first();
        $weekFourPracticeState = $trainingWeek->week_number === 4 && $activity->position === 2 ? $interviews->participantPracticeState($user, $interviewPartner) : null;
        $mentor = $trainingWeek->week_number === 4 && $activity->position === 2 ? $mentorScheduling->assignedMentor($user) : null;
        $mentorSchedule = $trainingWeek->week_number === 4 && $activity->position === 2
            ? WeekFourMentorInterviewSchedule::query()->where('participant_id', $user->id)->where(function ($query) use ($activity): void {
                $query->where('activity_id', $activity->id)->orWhereNull('activity_id');
            })->whereIn('status', [WeekFourMentorInterviewSchedule::PENDING, WeekFourMentorInterviewSchedule::SCHEDULED])->latest('id')->first()
            : null;
        $weekFourPartnerStatus = null;
        if ($interviewPartner && $trainingWeek->week_number === 4 && $activity->position === 2) {
            $weekFour = $trainingWeek->program->weeks()->where('week_number', 4)->first();
            $weekFourPartnerStatus = $weekFour && ($progression->canAccessWeek($interviewPartner, $weekFour) || $progression->isWeekPassed($interviewPartner, $weekFour)) ? 'unlocked' : 'locked';
        }
        $weekFourForPartners = $trainingWeek->week_number === 4 && $activity->position === 2 ? $trainingWeek->program->weeks()->where('week_number', 4)->first() : null;
        $partnerStatuses = $interviewPartners->mapWithKeys(function ($partner) use ($weekFourForPartners, $progression): array {
            $weekFour = $weekFourForPartners;

            return [$partner->id => $weekFour && ($progression->canAccessWeek($partner, $weekFour) || $progression->isWeekPassed($partner, $weekFour)) ? 'unlocked' : 'locked'];
        });
        $peerWaiver = $trainingWeek->week_number === 4 && $activity->position === 2 ? $interviews->waiverFor($user, $activity) : null;
        $peerPrerequisiteSatisfied = $trainingWeek->week_number === 4 && $activity->position === 2 ? $interviews->peerPrerequisiteSatisfied($user, $activity) : true;

        return response()->view('submissions.edit', ['user' => $user, 'trainingWeek' => $trainingWeek, 'activity' => $activity, 'submission' => $submission, 'version' => $version, 'fields' => $fields, 'evidenceRequirements' => $workflow->evidenceRequirements($activity, $user), 'labRecords' => $labRecords, 'selectedLabRecord' => $selectedLabRecord, 'weekThreeWorkspace' => $weekThreeWorkspace, 'weekThreePaths' => $weekThree->requiredPaths($user), 'interviewPair' => $interviewPair, 'interviewPairs' => $interviewPairs, 'interviewPartner' => $interviewPartner, 'interviewPartners' => $interviewPartners, 'partnerStatuses' => $partnerStatuses, 'feedbackGiven' => $feedbackGiven, 'feedbackReceived' => $feedbackReceived, 'partnerInterview' => $partnerInterview, 'weekFourPracticeState' => $weekFourPracticeState, 'weekFourPracticeSlots' => $interviews->practiceSlots(), 'mentor' => $mentor, 'mentorSchedule' => $mentorSchedule, 'weekFourPartnerStatus' => $weekFourPartnerStatus, 'peerWaiver' => $peerWaiver, 'peerPrerequisiteSatisfied' => $peerPrerequisiteSatisfied, 'participantCalendarEnabled' => $googleConfiguration->participantCalendarEnabled()])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function carryForwardLabRecord(Activity $activity, User $user, ?ActivitySubmission $submission, $labRecords): ?ValidationSubmission
    {
        if ($activity->week?->week_number !== 2 || $activity->position <= 1 || $submission?->versions->isNotEmpty()) {
            return null;
        }

        $previous = ActivitySubmission::query()
            ->where('user_id', $user->id)
            ->whereHas('activity', fn ($query) => $query->where('training_week_id', $activity->training_week_id)->where('position', '<', $activity->position))
            ->with(['versions' => fn ($query) => $query->orderByDesc('version_number')])
            ->orderByDesc('attempt_number')
            ->get()
            ->flatMap(fn ($item) => $item->versions)
            ->first(fn ($version) => filled($version->validation_submission_id));

        return $previous ? $labRecords->firstWhere('id', $previous->validation_submission_id) : null;
    }

    public function storeDraft(StoreSubmissionDraftRequest $request, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, SubmissionWorkflowService $workflow, GoogleDriveReadinessService $readiness, TrainingSyncCoordinator $syncs, WeekThreeWorkspaceService $weekThree): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->role === 'participant' && $readiness->enforced() && ! $readiness->isReady($user), 403, $readiness->message($user));
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $trainingWeek, $activity);
        $this->ensureValidationChecklistPrerequisite($user, $trainingWeek, $activity);
        if ($trainingWeek->week_number === 3) {
            $weekThree->workspace($user, $trainingWeek);
        }
        Gate::authorize('create', ActivitySubmission::class);
        $version = $workflow->saveDraft($user, $activity, $request->validated('field_values', []), $request->validated('participant_note'), $request->validated('interactions'));
        ExportActivityJob::dispatch($version->id);
        $syncs->request($user, 'activity_saved');

        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'version_id' => $version->id, 'status' => 'Saved']);
        }

        return redirect()->route('training.submission.edit', [$trainingWeek, $activity])->with('status', 'Draft saved.');
    }

    public function revise(Request $request, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, SubmissionWorkflowService $workflow, GoogleDriveReadinessService $readiness, TrainingSyncCoordinator $syncs): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->role === 'participant' && $readiness->enforced() && ! $readiness->isReady($user), 403, $readiness->message($user));
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $trainingWeek, $activity);
        $this->ensureValidationChecklistPrerequisite($user, $trainingWeek, $activity);
        $submission = ActivitySubmission::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->with('versions.interactions')
            ->orderByDesc('attempt_number')
            ->firstOrFail();
        $existingDraft = $submission->versions->sortByDesc('version_number')->firstWhere('status', 'draft');
        if ($existingDraft) {
            return redirect()->route('training.submission.edit', [$trainingWeek, $activity])->with('status', 'Revision draft already available.');
        }
        abort_unless($submission->status === 'needs_revision', 422);
        $latest = $submission->versions->sortByDesc('version_number')->firstOrFail();
        $version = $workflow->saveDraft($user, $activity, $latest->field_values ?? [], $latest->participant_note, $latest->interactions->map(fn ($interaction): array => ['prompt' => $interaction->prompt, 'output' => $interaction->output])->all());
        ExportActivityJob::dispatch($version->id);
        $syncs->request($user, 'activity_resubmitted');

        return redirect()->route('training.submission.edit', [$trainingWeek, $activity])->with('status', 'Revision draft created.');
    }

    public function submit(Request $request, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $submissionVersion, WeekProgressionService $progression, SubmissionWorkflowService $workflow, GoogleDriveReadinessService $readiness, TrainingSyncCoordinator $syncs, WeekThreeWorkspaceService $weekThree): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->role === 'participant' && $readiness->enforced() && ! $readiness->isReady($user), 403, $readiness->message($user));
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $trainingWeek, $activity);
        $this->ensureValidationChecklistPrerequisite($user, $trainingWeek, $activity);
        abort_unless($submissionVersion->submission->activity_id === $activity->id, 404);
        Gate::authorize('update', $submissionVersion);
        $weekThree->validateForSubmission($user, $activity);
        if ($trainingWeek->week_number === 3) {
            $submissionVersion->field_values = array_merge($submissionVersion->field_values ?? [], ['week_three_snapshot' => $weekThree->snapshot($user, $trainingWeek)]);
            $submissionVersion->save();
        }
        $workflow->submit($user, $submissionVersion);
        ExportActivityJob::dispatch($submissionVersion->id);
        $syncs->request($user, 'activity_submitted');

        return redirect()->route('training.submission.edit', [$trainingWeek, $activity])->with('status', 'Submission sent for review.');
    }

    private function isValidationChecklist(TrainingWeek $trainingWeek, Activity $activity): bool
    {
        return $trainingWeek->week_number === 2 && $activity->position === 1 && $activity->title === 'Validation Checklist';
    }

    private function hasSubmittedLabRecord(User $user): bool
    {
        return $user->validationSubmissions()->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->exists();
    }

    private function ensureValidationChecklistPrerequisite(User $user, TrainingWeek $trainingWeek, Activity $activity): void
    {
        if ($user->role === 'participant' && $this->isValidationChecklist($trainingWeek, $activity) && ! $this->hasSubmittedLabRecord($user)) {
            throw ValidationException::withMessages(['lab_prerequisite' => 'Submit a QA Validation Lab record before starting this activity.']);
        }
    }
}
