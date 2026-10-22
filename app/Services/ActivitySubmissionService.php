<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\ExportActivityJob;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ActivitySubmissionService
{
    public const LAB_PREREQUISITE_MESSAGE = 'Submit a QA Validation Lab record before starting this activity.';

    public function __construct(
        private readonly SubmissionWorkflowService $workflow,
        private readonly WeekThreeWorkspaceService $weekThree,
        private readonly TrainingSyncCoordinator $syncs,
    ) {}

    /**
     * Whether a participant must still submit a QA Validation Lab record before working on the Week 2 Validation Checklist.
     */
    public function isMissingLabPrerequisite(User $user, TrainingWeek $trainingWeek, Activity $activity): bool
    {
        return $user->hasAnyRole(UserRole::Participant)
            && $activity->isValidationChecklist($trainingWeek)
            && ! $user->validationSubmissions()->recorded()->exists();
    }

    /**
     * @throws ValidationException when the participant has no submitted Lab record for the Validation Checklist.
     */
    public function ensureLabPrerequisite(User $user, TrainingWeek $trainingWeek, Activity $activity): void
    {
        if ($this->isMissingLabPrerequisite($user, $trainingWeek, $activity)) {
            throw ValidationException::withMessages(['lab_prerequisite' => self::LAB_PREREQUISITE_MESSAGE]);
        }
    }

    /**
     * Save the participant's draft (opening the Week 3 workspace first), then queue the activity export and Drive sync.
     *
     * @param  array<string, mixed>  $fieldValues
     * @param  array<int, array{prompt?: ?string, output?: ?string}>|null  $interactions
     */
    public function saveDraft(User $participant, TrainingWeek $trainingWeek, Activity $activity, array $fieldValues, ?string $note, ?array $interactions): SubmissionVersion
    {
        if ($trainingWeek->week_number === 3) {
            $this->weekThree->workspace($participant, $trainingWeek);
        }
        $version = $this->workflow->saveDraft($participant, $activity, $fieldValues, $note, $interactions);
        $this->queueExportAndSync($participant, $version, 'activity_saved');

        return $version;
    }

    /**
     * The participant's latest attempt at the activity, with its versions and their interactions; a missing attempt is a 404.
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
     * Copy the attempt's latest version (answers, note and interactions) into a new draft, then queue the activity export and Drive sync.
     */
    public function createRevisionDraft(User $participant, Activity $activity, ActivitySubmission $submission): SubmissionVersion
    {
        $latest = $submission->versions->sortByDesc('version_number')->firstOrFail();
        $interactions = $latest->interactions->map(fn (SubmissionInteraction $interaction): array => ['prompt' => $interaction->prompt, 'output' => $interaction->output])->all();
        $version = $this->workflow->saveDraft($participant, $activity, $latest->field_values ?? [], $latest->participant_note, $interactions);
        $this->queueExportAndSync($participant, $version, 'activity_resubmitted');

        return $version;
    }

    /**
     * Submit the draft for review (checkpointing the Week 3 workspace onto it first), then queue the activity export and Drive sync.
     */
    public function submit(User $participant, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $version): void
    {
        $this->weekThree->validateForSubmission($participant, $activity);
        if ($trainingWeek->week_number === 3) {
            $version->field_values = array_merge($version->field_values ?? [], ['week_three_snapshot' => $this->weekThree->snapshot($participant, $trainingWeek)]);
            $version->save();
        }
        $this->workflow->submit($participant, $version);
        $this->queueExportAndSync($participant, $version, 'activity_submitted');
    }

    private function queueExportAndSync(User $participant, SubmissionVersion $version, string $trigger): void
    {
        ExportActivityJob::dispatch($version->id);
        $this->syncs->request($participant, $trigger);
    }
}
