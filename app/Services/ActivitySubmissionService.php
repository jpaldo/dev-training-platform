<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionVersion;
use App\Models\User;

class ActivitySubmissionService
{
    public function __construct(
        private readonly SubmissionWorkflowService $workflow,
        private readonly TrainingSyncCoordinator $syncs,
    ) {}

    /**
     * Save the participant's draft, then request a Drive sync.
     *
     * @param  array<string, mixed>  $fieldValues
     * @param  array<int, array{prompt?: ?string, output?: ?string}>|null  $interactions
     */
    public function saveDraft(User $participant, Activity $activity, array $fieldValues, ?string $note, ?array $interactions): SubmissionVersion
    {
        $version = $this->workflow->saveDraft($participant, $activity, $fieldValues, $note, $interactions);
        $this->syncs->request($participant, 'activity_saved');

        return $version;
    }

    /**
     * The participant's latest attempt at the activity, with its versions and their interactions; a missing attempt is
     * a 404.
     */
    public function latestAttempt(User $participant, Activity $activity): ActivitySubmission
    {
        return ActivitySubmission::query()
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
     * Copy the attempt's latest version (answers, note and interactions) into a new draft, then request a Drive sync.
     */
    public function createRevisionDraft(User $participant, Activity $activity, ActivitySubmission $submission): SubmissionVersion
    {
        $latest = $submission->versions->sortByDesc('version_number')->firstOrFail();
        $interactions = $latest->interactions->map(fn (SubmissionInteraction $interaction): array => ['prompt' => $interaction->prompt, 'output' => $interaction->output])->all();
        $version = $this->workflow->saveDraft($participant, $activity, $latest->field_values ?? [], $latest->participant_note, $interactions);
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
