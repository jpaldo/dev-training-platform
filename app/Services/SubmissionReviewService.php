<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Enums\SubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmissionReviewService
{
    public function __construct(private readonly TrainingSyncCoordinator $syncs) {}

    /**
     * Move a submitted or resubmitted submission to "under review".
     */
    public function claim(SubmissionVersion $submissionVersion): void
    {
        DB::transaction(function () use ($submissionVersion): void {
            $submission = $submissionVersion->submission()->lockForUpdate()->firstOrFail();
            if ($submission->isClaimable()) {
                $submission->update(['status' => SubmissionStatus::UnderReview]);
            }
        });
    }

    /**
     * Record a coach decision on a submission version and update the submission status in one transaction, then request
     * a Drive sync once both writes are committed.
     */
    public function record(SubmissionVersion $submissionVersion, User $reviewer, ReviewDecision $decision, ?string $comments): SubmissionReview
    {
        $review = DB::transaction(function () use ($submissionVersion, $reviewer, $decision, $comments): SubmissionReview {
            $review = SubmissionReview::create(['submission_version_id' => $submissionVersion->id, 'reviewer_id' => $reviewer->id, 'decision' => $decision, 'comments' => $comments, 'reviewed_at' => now()]);
            $status = $review->decision->submissionStatus();
            $submissionVersion->submission->update(['status' => $status, 'completed_at' => $status === SubmissionStatus::Approved ? now() : null]);

            return $review;
        });
        $this->syncs->request($submissionVersion->submission->participant, 'review_updated');

        return $review;
    }

    /**
     * Find a later attempt of the same activity in the same session that is still in progress or already passed.
     */
    public function existingRetry(ActivitySubmission $submission): ?ActivitySubmission
    {
        return ActivitySubmission::query()->where('training_session_id', $submission->training_session_id)->where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->where('attempt_number', '>', $submission->attempt_number)->whereIn('status', [SubmissionStatus::Draft, SubmissionStatus::NeedsRevision, ...SubmissionStatus::awaitingReview(), ...SubmissionStatus::accepted()])->first();
    }

    /**
     * Open the next attempt of a failed submission, in the same session, with an empty draft version.
     */
    public function createRetry(ActivitySubmission $submission): ActivitySubmission
    {
        return DB::transaction(function () use ($submission): ActivitySubmission {
            $attempt = ActivitySubmission::query()->where('training_session_id', $submission->training_session_id)->where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->max('attempt_number') + 1;
            $retry = ActivitySubmission::create(['training_session_id' => $submission->training_session_id, 'activity_id' => $submission->activity_id, 'user_id' => $submission->user_id, 'attempt_number' => $attempt, 'status' => SubmissionStatus::Draft]);
            $retry->versions()->create(['version_number' => 1, 'status' => SubmissionStatus::Draft, 'field_values' => []]);

            return $retry;
        });
    }
}
