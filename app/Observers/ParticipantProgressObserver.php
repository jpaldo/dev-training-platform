<?php

namespace App\Observers;

use App\Enums\UserRole;
use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\ProgramParticipant;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues a participant notification sync whenever a record that ParticipantNotificationService::sync() reads changes,
 * so page views only read notifications. Coach writes (reviews, Gate evaluations) queue the sync for the participant
 * they affect, and a new participant gets their first notifications without waiting for the hourly catch-up run.
 * Joining a curriculum (or being added to one) and being removed from one queue the sync too, so the curriculum's
 * notifications are raised, or resolved, straight away.
 * The job is unique per participant until it starts processing, so a request that writes several records
 * queues one sync.
 */
class ParticipantProgressObserver
{
    public function created(Model $model): void
    {
        $this->queueNotificationSync($model);
    }

    /**
     * A user update only feeds the sync when it makes the user a participant; profile and login changes do not. A
     * curriculum membership feeds it by existing, so updating one does not.
     */
    public function updated(Model $model): void
    {
        if ($model instanceof ProgramParticipant || ($model instanceof User && ! $model->wasChanged('role'))) {
            return;
        }

        $this->queueNotificationSync($model);
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof User) {
            return;
        }

        $this->queueNotificationSync($model);
    }

    private function queueNotificationSync(Model $model): void
    {
        $participantId = $this->affectedParticipantId($model);

        if ($participantId !== null) {
            GenerateParticipantNotificationsJob::dispatch($participantId);
        }
    }

    private function affectedParticipantId(Model $model): ?int
    {
        return match (true) {
            $model instanceof User => $model->role === UserRole::Participant ? $model->id : null,
            $model instanceof ActivitySubmission,
            $model instanceof WeeklyVideoRecording,
            $model instanceof GateEvaluation,
            $model instanceof ProgramParticipant => $model->user_id,
            $model instanceof SubmissionVersion => $this->submissionOwnerId($model->activity_submission_id),
            $model instanceof SubmissionReview,
            $model instanceof SubmissionEvidence => $this->versionOwnerId($model->submission_version_id),
            default => null,
        };
    }

    private function submissionOwnerId(int $submissionId): ?int
    {
        return ActivitySubmission::query()->whereKey($submissionId)->value('user_id');
    }

    private function versionOwnerId(int $versionId): ?int
    {
        return ActivitySubmission::query()
            ->whereHas('versions', fn (Builder $query): Builder => $query->whereKey($versionId))
            ->value('user_id');
    }
}
