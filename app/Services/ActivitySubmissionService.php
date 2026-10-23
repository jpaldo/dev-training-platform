<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\User;

class ActivitySubmissionService
{
    public function __construct(
        private readonly SubmissionWorkflowService $workflow,
        private readonly TrainingSyncCoordinator $syncs,
    ) {}

    /**
     * Save the participant's draft within the session, then request a Drive sync.
     *
     * @param  array<string, mixed>  $fieldValues
     * @param  array<int, array{prompt?: ?string, output?: ?string}>|null  $interactions
     */
    public function saveDraft(User $participant, TrainingSession $trainingSession, Activity $activity, array $fieldValues, ?string $note, ?array $interactions): SubmissionVersion
    {
        $version = $this->workflow->saveDraft($participant, $trainingSession, $activity, $fieldValues, $note, $interactions);
        $this->syncs->request($participant, 'activity_saved');

        return $version;
    }

    /**
     * The participant's latest attempt at the activity within the session, with its versions and their interactions;
     * a missing attempt is a 404.
     */
    public function latestAttempt(User $participant, TrainingSession $trainingSession, Activity $activity): ActivitySubmission
    {
        return ActivitySubmission::query()
            ->where('training_session_id', $trainingSession->id)
            ->where('activity_id', $activity->id)
            ->where('user_id', $participant->id)
            ->with('versions.interactions')
            ->orderByDesc('attempt_number')
            ->firstOrFail();
    }

    public function hasDraftVersion(ActivitySubmission $submission): bool
    {
        return $submission->versions->contains('status', SubmissionStatus::Draft);
    }

    /**
     * Copy the attempt's latest version (answers, note and interactions) into a new draft within the session, then
     * request a Drive sync.
     */
    public function createRevisionDraft(User $participant, TrainingSession $trainingSession, Activity $activity, ActivitySubmission $submission): SubmissionVersion
    {
        $latest = $submission->versions->sortByDesc('version_number')->firstOrFail();
        $interactions = $latest->interactions->map(fn (SubmissionInteraction $interaction): array => ['prompt' => $interaction->prompt, 'output' => $interaction->output])->all();
        $version = $this->workflow->saveDraft($participant, $trainingSession, $activity, $latest->field_values ?? [], $latest->participant_note, $interactions);
        $this->syncs->request($participant, 'activity_resubmitted');

        return $version;
    }

    /**
     * Submit the draft for review, then request a Drive sync.
     */
    public function submit(User $participant, SubmissionVersion $version): void
    {
        $this->workflow->submit($participant, $version);
        $this->syncs->request($participant, 'activity_submitted');
    }
}
