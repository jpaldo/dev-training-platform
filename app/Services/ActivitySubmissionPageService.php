<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivityScoreDimension;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Models\WeekFourInterviewPair;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ActivitySubmissionPageService
{
    /** @var array<string, string> Labels for answer keys that are no longer configured as submission fields. */
    private const LEGACY_LABELS = ['thread_drive_link' => 'Thread / Drive Link', 'ai_tool' => 'AI Tool', 'artifact_type' => 'Artifact Type', 'prompt' => 'Prompt', 'exact_ai_output' => 'Exact AI Output', 'ai_output' => 'AI Output', 'pre_acceptance_question' => 'Pre-acceptance Question', 'validation_performed' => 'Validation Performed'];

    public function __construct(
        private readonly SubmissionWorkflowService $workflow,
        private readonly WeekProgressionService $progression,
        private readonly WeekThreeWorkspaceService $weekThree,
        private readonly WeekFourInterviewService $interviews,
        private readonly MentorInterviewSchedulingService $mentorScheduling,
        private readonly GoogleDriveConfigurationService $googleConfiguration,
        private readonly SubmissionAnswerPresenter $answers,
        private readonly WeekFourReviewPresenter $weekFour,
    ) {}

    /**
     * Data for the submission form: the latest attempt and version, the fields and evidence requirements, the participant's
     * Lab records with the one to preselect, the Week 3 workspace and the Week 4 mock interview context.
     *
     * @return array<string, mixed>
     */
    public function editData(User $participant, TrainingWeek $trainingWeek, Activity $activity, int $requestedLabRecordId): array
    {
        $submission = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $participant->id)->with(['versions' => fn (Relation $query): Relation => $query->with(['evidence', 'reviews', 'validationSubmission', 'interactions'])->orderByDesc('version_number')])->orderByDesc('attempt_number')->first();
        $version = $submission?->versions->first();
        $fields = $this->workflow->fieldDefinitions($activity, $participant);
        $labRecords = array_key_exists('validation_submission_id', $fields) ? $this->submittedLabRecords($participant) : collect();
        $selectedLabRecord = $version?->validation_submission_id
            ? $labRecords->firstWhere('id', $version->validation_submission_id)
            : ($requestedLabRecordId ? $labRecords->firstWhere('id', $requestedLabRecordId) : $this->carryForwardLabRecord($activity, $participant, $submission, $labRecords));

        return [
            'user' => $participant,
            'trainingWeek' => $trainingWeek,
            'activity' => $activity,
            'submission' => $submission,
            'version' => $version,
            'fields' => $fields,
            'evidenceRequirements' => $this->workflow->evidenceRequirements($activity, $participant),
            'labRecords' => $labRecords,
            'selectedLabRecord' => $selectedLabRecord,
            'weekThreeWorkspace' => $trainingWeek->week_number === 3 ? $this->weekThree->workspace($participant, $trainingWeek)->load(['findings.tests.fixValidation']) : null,
            'weekThreePaths' => $this->weekThree->requiredPaths($participant),
            ...$this->weekFourInterviewData($participant, $trainingWeek, $activity),
            'participantCalendarEnabled' => $this->googleConfiguration->participantCalendarEnabled(),
        ];
    }

    /**
     * Data for a submitted version: the labelled answers, the participant's own Lab record, the published score and the
     * Week 3 and Week 4 review blocks.
     *
     * @return array{responses: Collection<int|string, array{label: string, value: mixed, markdown: bool}>, labRecord: ?ValidationSubmission, finalScore: ?ActivityScore, finalDimensions: Collection<string, ActivityScoreDimension>, weekThreeReview: ?array<string, mixed>, weekFourReview: ?array<string, mixed>}
     */
    public function showData(User $participant, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $version): array
    {
        $configured = collect($this->workflow->fieldDefinitions($activity, $participant))->mapWithKeys(fn (array $field): array => [$field['key'] => $field['label']])->all();
        $responses = collect($version->field_values ?? [])->except(['week_three_snapshot'])->map(fn (mixed $value, int|string $key): array => [
            'label' => $configured[$key] ?? self::LEGACY_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'value' => $value,
            'markdown' => in_array($key, Markdown::OUTPUT_FIELDS, true),
        ]);
        $finalScore = $version->activityScore?->status === 'published' ? $version->activityScore : null;
        $weekThreeSnapshot = $trainingWeek->week_number === 3 ? ($version->field_values['week_three_snapshot'] ?? null) : null;

        return [
            'responses' => $responses,
            'labRecord' => $version->validationSubmission?->user_id === $participant->id ? $version->validationSubmission : null,
            'finalScore' => $finalScore,
            'finalDimensions' => $finalScore?->dimensions?->keyBy('dimension') ?? collect(),
            'weekThreeReview' => $weekThreeSnapshot ? $this->answers->weekThreeReview($weekThreeSnapshot, (int) $activity->position, $version->submission->participant->training_path) : null,
            'weekFourReview' => $this->weekFour->present($activity, $version->field_values ?? []),
        ];
    }

    /**
     * The participant's submitted or completed Lab records, newest first.
     *
     * Always queried fresh and scoped to the owner on each form request; never derived from a draft, the session or an
     * activity snapshot.
     *
     * @return Collection<int, ValidationSubmission>
     */
    private function submittedLabRecords(User $participant): Collection
    {
        return ValidationSubmission::query()->where('user_id', $participant->id)->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->latest('submitted_at')->latest('id')->get();
    }

    /**
     * For a first attempt at a later Week 2 activity, the Lab record used by the participant's most recent earlier Week 2 version.
     *
     * @param  Collection<int, ValidationSubmission>  $labRecords
     */
    private function carryForwardLabRecord(Activity $activity, User $participant, ?ActivitySubmission $submission, Collection $labRecords): ?ValidationSubmission
    {
        if ($activity->week?->week_number !== 2 || $activity->position <= 1 || $submission?->versions->isNotEmpty()) {
            return null;
        }

        $previous = ActivitySubmission::query()
            ->where('user_id', $participant->id)
            ->whereHas('activity', fn (Builder $query): Builder => $query->where('training_week_id', $activity->training_week_id)->where('position', '<', $activity->position))
            ->with(['versions' => fn (Relation $query): Relation => $query->orderByDesc('version_number')])
            ->orderByDesc('attempt_number')
            ->get()
            ->flatMap(fn (ActivitySubmission $item): Collection => $item->versions)
            ->first(fn (SubmissionVersion $version): bool => filled($version->validation_submission_id));

        return $previous ? $labRecords->firstWhere('id', $previous->validation_submission_id) : null;
    }

    /**
     * The Week 4 Activity 2 mock interview context: interview pairs and partners with their Week 4 access, feedback, the
     * partner's interview, the practice state, the mentor and open schedule, and the peer interview waiver.
     *
     * @return array<string, mixed>
     */
    private function weekFourInterviewData(User $participant, TrainingWeek $trainingWeek, Activity $activity): array
    {
        if ($trainingWeek->week_number !== 4 || $activity->position !== 2) {
            return [
                'interviewPair' => null,
                'interviewPairs' => collect(),
                'interviewPartner' => null,
                'interviewPartners' => collect(),
                'partnerStatuses' => collect(),
                'feedbackGiven' => collect(),
                'feedbackReceived' => collect(),
                'partnerInterview' => null,
                'weekFourPracticeState' => null,
                'weekFourPracticeSlots' => $this->interviews->practiceSlots(),
                'mentor' => null,
                'mentorSchedule' => null,
                'weekFourPartnerStatus' => null,
                'peerWaiver' => null,
                'peerPrerequisiteSatisfied' => true,
            ];
        }

        $interviewPairs = $this->interviews->pairsFor($participant);
        $interviewPair = $interviewPairs->first();
        $interviewPartner = $interviewPair?->partnerFor($participant->id);
        $interviewPartners = $interviewPairs->map(fn (WeekFourInterviewPair $pair): ?User => $pair->partnerFor($participant->id))->filter()->values();
        $weekFour = $trainingWeek->program->weeks()->where('week_number', 4)->first();

        return [
            'interviewPair' => $interviewPair,
            'interviewPairs' => $interviewPairs,
            'interviewPartner' => $interviewPartner,
            'interviewPartners' => $interviewPartners,
            'partnerStatuses' => $interviewPartners->mapWithKeys(fn (User $partner): array => [$partner->id => $this->weekFourStatus($partner, $weekFour)]),
            'feedbackGiven' => $interviewPair?->feedback()->where('author_id', $participant->id)->get()->keyBy('question_number') ?? collect(),
            'feedbackReceived' => $interviewPair?->feedback()->where('recipient_id', $participant->id)->get()->keyBy('question_number') ?? collect(),
            'partnerInterview' => $interviewPartner?->activitySubmissions()->where('activity_id', $activity->id)->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', ['submitted', 'resubmitted', 'approved', 'completed'])->orderByDesc('version_number')])->latest('attempt_number')->first()?->versions->first(),
            'weekFourPracticeState' => $this->interviews->participantPracticeState($participant, $interviewPartner),
            'weekFourPracticeSlots' => $this->interviews->practiceSlots(),
            'mentor' => $this->mentorScheduling->assignedMentor($participant),
            'mentorSchedule' => $this->mentorScheduling->openScheduleFor($participant, $activity),
            'weekFourPartnerStatus' => $interviewPartner ? $this->weekFourStatus($interviewPartner, $weekFour) : null,
            'peerWaiver' => $this->interviews->waiverFor($participant, $activity),
            'peerPrerequisiteSatisfied' => $this->interviews->peerPrerequisiteSatisfied($participant, $activity),
        ];
    }

    /**
     * Whether a mock interview partner has reached Week 4 ("unlocked") or not ("locked").
     */
    private function weekFourStatus(User $partner, ?TrainingWeek $weekFour): string
    {
        return $weekFour && ($this->progression->canAccessWeek($partner, $weekFour) || $this->progression->isWeekPassed($partner, $weekFour)) ? 'unlocked' : 'locked';
    }
}
