<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivityScoreDimension;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourPartnerFeedback;
use App\Support\FieldLimits;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ActivitySubmissionPageService
{
    /** @var array<string, array{string, string}> The Validation Lab checks shown in the Lab record dialog: label => [status column, notes column]. */
    private const LAB_RECORD_CHECKS = ['SOURCE' => ['source_status', 'source_notes'], 'SCOPE' => ['scope_status', 'scope_notes'], 'SPEC' => ['spec_status', 'spec_notes'], 'CROSS-CHECK' => ['cross_check_status', 'cross_check_notes'], 'RUN' => ['run_status', 'run_notes'], 'LOG' => ['log_status', 'log_notes']];

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
        private readonly SafeMarkdownRenderer $markdown,
        private readonly EvidenceFilePolicy $evidenceFiles,
    ) {}

    /**
     * Data for the submission form: the latest attempt and version, the main action, the coach feedback, the field
     * sections and evidence rows, the participant's Lab records with the one to preselect, the version history, the
     * Week 3 workspace and the Week 4 mock interview context.
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
        $isPeerInterview = $trainingWeek->week_number === 4 && $activity->position === 2;
        $showInteractions = collect($fields)->keys()->intersect(['prompt', 'exact_prompt', 'ai_output', 'exact_ai_output'])->isNotEmpty();
        /** @var ?SubmissionReview $coachFeedback */
        $coachFeedback = $submission?->versions->flatMap->reviews->sortByDesc('reviewed_at')->firstWhere('decision', 'needs_revision');

        return [
            'user' => $participant,
            'trainingWeek' => $trainingWeek,
            'activity' => $activity,
            'submission' => $submission,
            'version' => $version,
            'isEditable' => ! $version || $version->status === 'draft',
            'mainAction' => match (true) {
                $version?->status === 'draft' => 'submit',
                $submission?->status === 'needs_revision' => 'revise',
                default => null,
            },
            'coachFeedback' => $coachFeedback?->comments ? $this->markdown->render($coachFeedback->comments) : null,
            'isPeerInterview' => $isPeerInterview,
            'showInteractions' => $showInteractions,
            'interactionRows' => $showInteractions ? $this->interactionRows($version) : [],
            'fieldSections' => $isPeerInterview ? [] : $this->fieldSections($fields),
            'artifactTypes' => config('validation_lab.artifact_types'),
            'evidenceRows' => $this->evidenceRows($this->workflow->evidenceRequirements($activity, $participant), $version),
            'labRecords' => $labRecords,
            'selectedLabRecord' => $selectedLabRecord,
            'labRecordChecks' => $this->labRecordChecks($selectedLabRecord),
            'versionRows' => $this->versionRows($submission, $version),
            'weekThreeWorkspace' => $trainingWeek->week_number === 3 ? $this->weekThree->workspace($participant, $trainingWeek)->load(['findings.tests.fixValidation']) : null,
            'weekThreePaths' => $this->weekThree->requiredPaths($participant),
            ...$this->weekFourInterviewData($participant, $trainingWeek, $activity, $version),
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
     * The Week 4 Activity 2 mock interview context: the partners with their Week 4 access, feedback, the practice state
     * and question rows, the peer interview progress, the mentor and open schedule, and the peer interview waiver.
     *
     * @return array<string, mixed>
     */
    private function weekFourInterviewData(User $participant, TrainingWeek $trainingWeek, Activity $activity, ?SubmissionVersion $version): array
    {
        $mentorInterview = [
            'mentorInterviewTimezone' => config('training.week_four_mentor_interview_timezone'),
            'mentorInterviewDurationMinutes' => config('training.week_four_mentor_interview_duration_minutes'),
        ];

        if ($trainingWeek->week_number !== 4 || $activity->position !== 2) {
            return [
                'interviewPartner' => null,
                'partnerRows' => [],
                'feedbackGiven' => collect(),
                'feedbackReceived' => collect(),
                'weekFourPracticeState' => null,
                'weekFourPracticeSlots' => $this->interviews->practiceSlots(),
                'practiceRows' => [],
                'peerInterviewProgress' => [],
                'mentor' => null,
                'mentorSchedule' => null,
                ...$mentorInterview,
                'peerWaiver' => null,
                'peerPrerequisiteSatisfied' => true,
            ];
        }

        $interviewPairs = $this->interviews->pairsFor($participant);
        $interviewPair = $interviewPairs->first();
        $interviewPartner = $interviewPair?->partnerFor($participant->id);
        $interviewPartners = $interviewPairs->map(fn (WeekFourInterviewPair $pair): ?User => $pair->partnerFor($participant->id))->filter()->values();
        $weekFour = $trainingWeek->program->weeks()->where('week_number', 4)->first();
        $feedbackGiven = $interviewPair?->feedback()->where('author_id', $participant->id)->get()->keyBy('question_number') ?? collect();
        $feedbackReceived = $interviewPair?->feedback()->where('recipient_id', $participant->id)->get()->keyBy('question_number') ?? collect();
        $practiceState = $this->interviews->participantPracticeState($participant, $interviewPartner);

        return [
            'interviewPartner' => $interviewPartner,
            'partnerRows' => $interviewPartners->map(fn (User $partner): array => [
                'partner' => $partner,
                'week_four_unlocked' => $this->weekFourStatus($partner, $weekFour) === 'unlocked',
            ])->all(),
            'feedbackGiven' => $feedbackGiven,
            'feedbackReceived' => $feedbackReceived,
            'weekFourPracticeState' => $practiceState,
            'weekFourPracticeSlots' => $this->interviews->practiceSlots(),
            'practiceRows' => $this->practiceRows($practiceState),
            'peerInterviewProgress' => $this->peerInterviewProgress($interviewPartner, $version, $practiceState, $feedbackGiven, $feedbackReceived),
            'mentor' => $this->mentorScheduling->assignedMentor($participant),
            'mentorSchedule' => $this->mentorScheduling->openScheduleFor($participant, $activity),
            ...$mentorInterview,
            'peerWaiver' => $this->interviews->waiverFor($participant, $activity),
            'peerPrerequisiteSatisfied' => $this->interviews->peerPrerequisiteSatisfied($participant, $activity),
        ];
    }

    /**
     * One row per practice question slot: the partner's question and my answer to it, my own question, the partner's
     * answer to it, and whether that answer locks my question.
     *
     * @param  array<string, mixed>  $practiceState
     * @return list<array{number: int, partner_question: mixed, answer: mixed, own_question: mixed, partner_answer: mixed, is_locked: bool}>
     */
    private function practiceRows(array $practiceState): array
    {
        return array_map(fn (int $number): array => [
            'number' => $number,
            'partner_question' => $practiceState['partner_questions'][$number] ?? '',
            'answer' => $practiceState['answers_to_partner'][$number] ?? '',
            'own_question' => $practiceState['own_questions'][$number] ?? '',
            'partner_answer' => $practiceState['partner_answers'][$number] ?? null,
            'is_locked' => filled($practiceState['partner_answers'][$number] ?? null),
        ], $this->interviews->practiceSlots());
    }

    /**
     * The peer interview checklist: partner assigned, About You, 15 own and 15 answered practice questions, and two
     * pieces of feedback given and received.
     *
     * @param  array<string, mixed>  $practiceState
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackGiven
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackReceived
     * @return list<array{label: string, complete: bool}>
     */
    private function peerInterviewProgress(?User $interviewPartner, ?SubmissionVersion $version, array $practiceState, Collection $feedbackGiven, Collection $feedbackReceived): array
    {
        $isFilled = fn (mixed $value): bool => filled($value);
        $preparedCount = count(array_filter($practiceState['own_questions'] ?? [], $isFilled));
        $answeredCount = count(array_filter($practiceState['answers_to_partner'] ?? [], $isFilled));
        $feedbackGivenCount = $feedbackGiven->filter(fn (WeekFourPartnerFeedback $item): bool => filled($item->content))->count();
        $feedbackReceivedCount = $feedbackReceived->filter(fn (WeekFourPartnerFeedback $item): bool => filled($item->content))->count();

        return [
            ['label' => 'Partner assigned', 'complete' => $interviewPartner !== null],
            ['label' => 'About You', 'complete' => filled($version?->field_values['about_you'] ?? null)],
            ['label' => 'My practice questions: '.$preparedCount.' / 15', 'complete' => $preparedCount >= 15],
            ['label' => 'Partner questions answered: '.$answeredCount.' / 15', 'complete' => $answeredCount >= 15],
            ['label' => 'Feedback given: '.$feedbackGivenCount.' / 2 required', 'complete' => $feedbackGivenCount >= 2],
            ['label' => 'Feedback received: '.$feedbackReceivedCount.' / 2 required', 'complete' => $feedbackReceivedCount >= 2],
        ];
    }

    /**
     * The form fields grouped under their section headings: the AI / artifact context section opens before its first
     * field and the QA work section before the first other field, each with the control to render and its length limit.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<array{title: string, description: string, fields: list<array<string, mixed>>}>
     */
    private function fieldSections(array $fields): array
    {
        $sections = [];
        $artifactContextShown = false;
        $activityWorkShown = false;

        foreach ($fields as $key => $field) {
            $key = (string) $key;
            $isArtifactContext = ($field['section'] ?? null) === 'artifact_context';
            if ($isArtifactContext && ! $artifactContextShown) {
                $sections[] = ['title' => '1. AI / ARTIFACT CONTEXT', 'description' => 'Document the AI artifact you are working with before recording your activity-specific QA work.', 'fields' => []];
                $artifactContextShown = true;
            } elseif (! $isArtifactContext && ! $activityWorkShown) {
                $sections[] = ['title' => ($artifactContextShown ? '2.' : '1.').' YOUR QA WORK', 'description' => 'Complete the activity-specific questions in the configured workflow order.', 'fields' => []];
                $activityWorkShown = true;
            }

            $sections[array_key_last($sections)]['fields'][] = [
                ...$field,
                'key' => $key,
                'control' => $this->fieldControl($key, $field),
                'max_length' => FieldLimits::forField($key, $field['type'] ?? 'textarea'),
                'has_length_note' => in_array($key, ['exact_prompt', 'exact_ai_output', 'ai_output', 'prompt'], true),
            ];
        }

        return $sections;
    }

    /**
     * The control a form field renders as: date, Lab record select, artifact type select, select, URL, text, Markdown
     * editor with preview, or plain textarea.
     *
     * @param  array<string, mixed>  $field
     */
    private function fieldControl(string $key, array $field): string
    {
        $type = $field['type'] ?? 'textarea';

        return match (true) {
            $type === 'date' => 'date',
            $key === 'validation_submission_id' => 'lab_record',
            $key === 'artifact_type' => 'artifact_type',
            in_array($type, ['select', 'url', 'text'], true) => $type,
            Markdown::editorPreview($key) => 'markdown',
            default => 'textarea',
        };
    }

    /**
     * The prompt / output pairs of the version, or one empty pair to start from.
     *
     * @return list<array{prompt: ?string, output: ?string}>
     */
    private function interactionRows(?SubmissionVersion $version): array
    {
        return $version?->interactions->map(fn (SubmissionInteraction $interaction): array => ['prompt' => $interaction->prompt, 'output' => $interaction->output])->values()->all()
            ?: [['prompt' => '', 'output' => '']];
    }

    /**
     * One row per evidence requirement: the version's evidence for it, the upload input (text, link or file) and its
     * file upload label, the accepted file types and the size hint.
     *
     * @param  Collection<int, EvidenceRequirement>  $requirements
     * @return Collection<int, array{requirement: EvidenceRequirement, evidence: Collection<int, array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, input: string, file_label: string, accept: string, size_hint: string, is_video: bool, is_reusable_asset: bool}>
     */
    private function evidenceRows(Collection $requirements, ?SubmissionVersion $version): Collection
    {
        $evidence = $version?->evidence ?? collect();
        $videoSizeHint = 'Upload a video file · maximum '.round(config('training.weekly_video_max_kb', 358400) / 1024, 1).' MB';

        return $requirements->map(function (EvidenceRequirement $requirement) use ($evidence, $videoSizeHint): array {
            $isVideo = $requirement->evidence_type === 'video';
            $isReusableAsset = $requirement->evidence_type === 'asset';
            $input = match (true) {
                $requirement->evidence_type === 'text' => 'text',
                in_array($requirement->evidence_type, ['link', 'url', 'video', 'repository'], true) => 'link',
                default => 'file',
            };

            return [
                'requirement' => $requirement,
                'evidence' => $evidence->where('evidence_requirement_id', $requirement->id)->map(function (SubmissionEvidence $item): array {
                    $isFile = filled($item->disk) && filled($item->path) && filled($item->original_filename);

                    return ['evidence' => $item, 'is_file' => $isFile, 'type_label' => $isFile ? 'Uploaded File' : ucfirst($item->evidence_type)];
                })->values()->toBase(),
                'input' => $input,
                'file_label' => match ($input) {
                    'text' => 'Or upload artifact',
                    'link' => 'Or upload file',
                    default => 'File',
                },
                'accept' => match (true) {
                    $isReusableAsset => $this->evidenceFiles->reusableAssetAcceptAttribute(),
                    $isVideo => 'video/*',
                    default => $this->evidenceFiles->acceptAttribute(),
                },
                'size_hint' => $isVideo ? $videoSizeHint : 'You can select and upload multiple files. Maximum file size: 300 MB per file.',
                'is_video' => $isVideo,
                'is_reusable_asset' => $isReusableAsset,
            ];
        })->values();
    }

    /**
     * The six Validation Lab checks of the selected Lab record with their status and notes.
     *
     * @return list<array{label: string, status: string, notes: string}>
     */
    private function labRecordChecks(?ValidationSubmission $record): array
    {
        if (! $record) {
            return [];
        }

        $checks = [];
        foreach (self::LAB_RECORD_CHECKS as $label => [$status, $notes]) {
            $checks[] = ['label' => $label, 'status' => strtoupper($record->{$status} ?? 'Not provided'), 'notes' => $record->{$notes} ?: 'Not provided'];
        }

        return $checks;
    }

    /**
     * The version history, newest first, with each version's status label and tone and whether it is the one being edited.
     *
     * @return list<array{version: SubmissionVersion, status_label: string, tone: string, is_current: bool}>
     */
    private function versionRows(?ActivitySubmission $submission, ?SubmissionVersion $version): array
    {
        return ($submission?->versions ?? collect())->map(fn (SubmissionVersion $historic): array => [
            'version' => $historic,
            'status_label' => ucfirst(str_replace('_', ' ', $historic->status)),
            'tone' => match ($historic->status) {
                'approved', 'completed' => 'success',
                'failed' => 'danger',
                'needs_revision' => 'warning',
                default => 'neutral',
            },
            'is_current' => $version !== null && $historic->is($version),
        ])->values()->all();
    }

    /**
     * Whether a mock interview partner has reached Week 4 ("unlocked") or not ("locked").
     */
    private function weekFourStatus(User $partner, ?TrainingWeek $weekFour): string
    {
        return $weekFour && ($this->progression->canAccessWeek($partner, $weekFour) || $this->progression->isWeekPassed($partner, $weekFour)) ? 'unlocked' : 'locked';
    }
}
