<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Enums\SubmissionStatus;
use App\Jobs\ExportActivityJob;
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
                $submission->update(['status' => 'under_review']);
            }
        });
    }

    /**
     * Record a coach decision on a submission version, update the submission status and queue its export and sync.
     */
    public function record(SubmissionVersion $submissionVersion, User $reviewer, ReviewDecision $decision, ?string $comments): SubmissionReview
    {
        $review = SubmissionReview::create(['submission_version_id' => $submissionVersion->id, 'reviewer_id' => $reviewer->id, 'decision' => $decision, 'comments' => $comments, 'reviewed_at' => now()]);
        $status = $review->decision->submissionStatus();
        $submissionVersion->submission->update(['status' => $status, 'completed_at' => $status === SubmissionStatus::Approved ? now() : null]);
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
