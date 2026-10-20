<?php

namespace App\Services;

use App\Jobs\ExportActivityJob;
use App\Models\ActivitySubmission;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmissionReviewService
{
    /** @var array<string, string> Submission status that each review decision leads to. */
    private const STATUS_BY_DECISION = ['approved' => 'approved', 'needs_revision' => 'needs_revision', 'rejected' => 'failed'];

    public function __construct(
        private readonly SubmissionAnswerPresenter $answers,
        private readonly WeekFourReviewPresenter $weekFour,
        private readonly WeekFourInterviewService $interviews,
        private readonly TrainingSyncCoordinator $syncs,
    ) {}

    /**
     * Prepare the coach review page of a submission version.
     *
     * @return array{submissionVersion: SubmissionVersion, isActivityOne: bool, submittedFields: array<int, array<string, mixed>>, linkedValidationSubmission: mixed, canManage: bool, weekThreeSnapshot: mixed, weekThreeReview: ?array<string, mixed>, weekFourReview: ?array<string, mixed>, officialQuestions: array<int, array<string, mixed>>}
     */
    public function reviewPage(SubmissionVersion $submissionVersion, User $viewer): array
    {
        $submissionVersion->load(['submission.participant', 'submission.activity.week', 'evidence', 'reviews.reviewer', 'validationSubmission', 'interactions']);
        $submission = $submissionVersion->submission;
        $activity = $submission->activity;
        $linkedValidationSubmission = $submissionVersion->validationSubmission;
        if (! $linkedValidationSubmission || (int) $linkedValidationSubmission->user_id !== (int) $submission->user_id) {
            $linkedValidationSubmission = null;
        }
        $weekThreeSnapshot = $activity->week->week_number === 3 ? ($submissionVersion->field_values['week_three_snapshot'] ?? null) : null;

        return [
            'submissionVersion' => $submissionVersion,
            'isActivityOne' => $activity->title === 'Validation Checklist' && $activity->week->week_number === 2,
            'submittedFields' => $this->answers->fields($submissionVersion),
            'linkedValidationSubmission' => $linkedValidationSubmission,
            'canManage' => $viewer->canManageParticipant($submission->participant),
            'weekThreeSnapshot' => $weekThreeSnapshot,
            'weekThreeReview' => $weekThreeSnapshot ? $this->answers->weekThreeReview($weekThreeSnapshot, (int) $activity->position, $submission->participant->training_path) : null,
            'weekFourReview' => $this->weekFour->present($activity, $submissionVersion->field_values ?? [], true),
            'officialQuestions' => $activity->week->week_number === 4 && $activity->position === 2 ? $this->interviews->questionnaire() : [],
        ];
    }

    /**
     * Move a submitted or resubmitted submission to "under review".
     */
    public function claim(SubmissionVersion $submissionVersion): void
    {
        DB::transaction(function () use ($submissionVersion): void {
            $submission = $submissionVersion->submission()->lockForUpdate()->firstOrFail();
            if (in_array($submission->status, ['submitted', 'resubmitted'], true)) {
                $submission->update(['status' => 'under_review']);
            }
        });
    }

    /**
     * Record a coach decision on a submission version, update the submission status and queue its export and sync.
     */
    public function record(SubmissionVersion $submissionVersion, User $reviewer, string $decision, ?string $comments): SubmissionReview
    {
        $review = SubmissionReview::create(['submission_version_id' => $submissionVersion->id, 'reviewer_id' => $reviewer->id, 'decision' => $decision, 'comments' => $comments, 'reviewed_at' => now()]);
        $status = self::STATUS_BY_DECISION[$review->decision];
        $submissionVersion->submission->update(['status' => $status, 'completed_at' => $status === 'approved' ? now() : null]);
        ExportActivityJob::dispatch($submissionVersion->id);
        $this->syncs->request($submissionVersion->submission->participant, 'review_updated');

        return $review;
    }

    /**
     * Find a later attempt of the same activity that is still in progress or already passed.
     */
    public function existingRetry(ActivitySubmission $submission): ?ActivitySubmission
    {
        return ActivitySubmission::query()->where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->where('attempt_number', '>', $submission->attempt_number)->whereIn('status', ['draft', 'submitted', 'under_review', 'needs_revision', 'approved', 'completed'])->first();
    }

    /**
     * Open the next attempt of a failed submission with an empty draft version.
     */
    public function createRetry(ActivitySubmission $submission): ActivitySubmission
    {
        return DB::transaction(function () use ($submission): ActivitySubmission {
            $attempt = ActivitySubmission::query()->where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->max('attempt_number') + 1;
            $retry = ActivitySubmission::create(['activity_id' => $submission->activity_id, 'user_id' => $submission->user_id, 'attempt_number' => $attempt, 'status' => 'draft']);
            $retry->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => []]);

            return $retry;
        });
    }
}
