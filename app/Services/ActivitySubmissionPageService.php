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
use App\Models\WeekThreeFinding;
use App\Models\WeekThreeTest;
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

    /** @var array<string, array{string, bool, int}> The Week 3 Activity 1 finding fields: key => [label, required, rows]; `human_validation` is a select. */
    private const WEEK_THREE_FINDING_FIELDS = [
        'title' => ['Issue title', true, 2],
        'description' => ['Description', true, 4],
        'steps_to_reproduce' => ['Steps to reproduce', true, 4],
        'expected_result' => ['Expected result', true, 4],
        'actual_result' => ['Actual result', true, 4],
        'observed_evidence' => ['Observed behavior / evidence', false, 4],
        'ai_tool' => ['AI tool used (optional)', false, 4],
        'ai_proposed_root_cause' => ['AI-proposed root cause (optional)', false, 4],
        'human_validation' => ['Human validation', true, 1],
        'human_validation_explanation' => ['Evidence supporting your assessment', true, 4],
        'severity' => ['Severity', true, 2],
        'blast_radius' => ['Blast radius', true, 2],
        'affected_areas' => ['Affected features / areas', true, 4],
        'next_step' => ['Suggested fix or next diagnostic step', true, 4],
    ];

    /** @var array<string, string> The human validation assessments of a Week 3 finding. */
    private const WEEK_THREE_HUMAN_VALIDATIONS = ['supported' => 'Supported', 'partially_supported' => 'Partially Supported', 'unsupported' => 'Unsupported'];

    /** @var array<string, array{string, int}> The fields of a Week 3 test on every path: key => [label, rows]. */
    private const WEEK_THREE_TEST_FIELDS = ['name' => ['Test case name', 2], 'preconditions' => ['Preconditions', 3], 'steps' => ['Test steps', 3], 'expected_result' => ['Expected result', 3], 'actual_result' => ['Actual result', 3], 'execution_result' => ['Execution result / status', 3]];

    /** @var array<string, array<string, array{string, int}>> The path-specific fields of a Week 3 test: path => key => [label, rows]. */
    private const WEEK_THREE_PATH_TEST_FIELDS = [
        'automation' => ['framework' => ['Framework', 2], 'script_location' => ['Script / test location', 2], 'artifact_evidence' => ['Artifact evidence', 2], 'execution_evidence' => ['Execution report / evidence', 2]],
        'manual' => ['execution_evidence' => ['Test evidence', 3]],
    ];

    /** @var array<string, string> The text fields of a Week 3 fix validation: key => label. */
    private const WEEK_THREE_FIX_FIELDS = ['buggy_result' => 'Buggy branch result (l3/week-3)', 'bug_free_result' => 'Bug-free branch result (l3/week-3-bug-free)', 'evidence' => 'Supporting evidence'];

    /** @var array<string, string> The statuses of a Week 3 fix validation. */
    private const WEEK_THREE_FIX_STATUSES = ['fixed' => 'Fixed', 'partially_fixed' => 'Partially Fixed', 'not_fixed' => 'Not Fixed', 'unable_to_verify' => 'Unable to Verify'];

    /** @var array<string, string> The Week 3 Activity 3 regression summary fields: key => label. */
    private const WEEK_THREE_REGRESSION_FIELDS = ['regression_areas' => 'Areas tested', 'regression_issues' => 'Regression issues discovered', 'coverage_gaps' => 'Coverage gaps — what might these tests still miss?', 'final_conclusion' => 'Final conclusion'];

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
     * Week 3 workspace, and for Week 4 the activity guide and the mock interview context.
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
        $weekFourInterview = $this->weekFourInterviewData($participant, $trainingWeek, $activity, $version);

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
            'weekThreeWorkspace' => $trainingWeek->week_number === 3 ? $this->weekThreeWorkspace($participant, $trainingWeek, $activity) : null,
            ...$weekFourInterview,
            'weekFourGuide' => $trainingWeek->week_number === 4 ? $this->weekFourGuide($activity, $version, $weekFourInterview['partnerFeedback']) : null,
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
     * The Week 4 Activity 2 mock interview context: the partners with their Week 4 access, the partner feedback counts
     * and rows, the practice question rows, the peer interview progress, the mentor and open schedule, and the peer
     * interview waiver.
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
                'partnerFeedback' => null,
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
            'partnerFeedback' => $this->partnerFeedback($practiceState, $feedbackGiven, $feedbackReceived),
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
     * The Week 4 activity guide: the activity heading and its progress checklist, read from the version's answers (and,
     * on Activity 2, the partner feedback counts). The evidence and "Ready to submit" steps are never shown as complete.
     *
     * @param  ?array{given_count: int, received_count: int}  $partnerFeedback
     * @return array{title: string, progress: list<array{label: string, complete: bool}>}
     */
    private function weekFourGuide(Activity $activity, ?SubmissionVersion $version, ?array $partnerFeedback): array
    {
        $values = $version?->field_values ?? [];
        $isAnswered = fn (string $key): bool => filled($values[$key] ?? null);
        $filledCount = fn (string $key): int => collect($values[$key] ?? [])->filter(fn (mixed $value): bool => filled($value))->count();

        [$title, $steps] = match ($activity->position) {
            1 => ['Build & Validate a Reusable AI Asset', [
                'Asset' => $isAnswered('asset_name'),
                'Original use' => $isAnswered('original_task'),
                'Second task' => $isAnswered('second_task'),
                'Reflection' => $isAnswered('reusability_learning'),
                'Evidence' => false,
            ]],
            2 => ['Mock Level 3 Interview', [
                'Partner selected' => $isAnswered('partner_user_id'),
                'About You' => $isAnswered('about_you'),
                'Practice questions prepared' => $filledCount('practice_questions') >= 15,
                'Partner questions answered' => $filledCount('practice_answers') >= 15,
                'Partner feedback (2 required)' => ($partnerFeedback['given_count'] ?? 0) >= 2 && ($partnerFeedback['received_count'] ?? 0) >= 2,
                'Ready to submit' => false,
            ]],
            default => ['Strict-mode Leveling Assessment', [
                'Transcript' => $isAnswered('strict_interview_transcript'),
                'Tracker result' => $isAnswered('assessment_tracker_result'),
                'Level' => $isAnswered('assessment_level'),
                'Confidence' => $isAnswered('assessment_confidence'),
                'Evidence' => false,
            ]],
        };

        return [
            'title' => $title,
            'progress' => array_map(fn (string $label, bool $complete): array => ['label' => $label, 'complete' => $complete], array_keys($steps), array_values($steps)),
        ];
    }

    /**
     * The partner feedback of the Week 4 Activity 2 guide: how many pieces of feedback were given and received, one row
     * per own question the partner has answered (with my saved feedback), and one row per question the partner has
     * prepared for me (with the partner's feedback on my answer). Without a partner both lists are empty.
     *
     * @param  array<string, mixed>  $practiceState
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackGiven
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackReceived
     * @return array{given_count: int, received_count: int, given_rows: list<array{number: int, question: mixed, answer: mixed, feedback: ?string}>, received_rows: list<array{number: int, question: mixed, feedback: ?string}>}
     */
    private function partnerFeedback(array $practiceState, Collection $feedbackGiven, Collection $feedbackReceived): array
    {
        $givenRows = [];
        $receivedRows = [];

        foreach ($this->interviews->practiceSlots() as $number) {
            $question = $practiceState['own_questions'][$number] ?? '';
            $answer = $practiceState['partner_answers'][$number] ?? '';
            if (filled($question) && filled($answer)) {
                $givenRows[] = ['number' => $number, 'question' => $question, 'answer' => $answer, 'feedback' => $feedbackGiven->get($number)?->content];
            }

            $partnerQuestion = $practiceState['partner_questions'][$number] ?? '';
            if (filled($partnerQuestion)) {
                $receivedRows[] = ['number' => $number, 'question' => $partnerQuestion, 'feedback' => $feedbackReceived->get($number)?->content];
            }
        }

        return [
            'given_count' => $feedbackGiven->count(),
            'received_count' => $feedbackReceived->count(),
            'given_rows' => $givenRows,
            'received_rows' => $receivedRows,
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
     * The Week 3 workspace that the three activities share: the activity's stage (1–3), the findings / tests / fix
     * validations progress, one entry per finding slot, and on Activity 3 the regression summary fields. Activity 1
     * edits the findings; Activities 2 and 3 show each finding read-only and edit one test per required path, and
     * Activity 3 also edits the fix validation of each saved test.
     *
     * @return array{stage: int, progress: list<array{label: string, count: int, is_complete: bool}>, findings: list<array{number: int, finding: ?WeekThreeFinding, fields: list<array<string, mixed>>, tests: list<array{heading: string, fields: list<array<string, mixed>>, fix_fields: list<array<string, mixed>>}>}>, regression_fields: list<array<string, mixed>>}
     */
    private function weekThreeWorkspace(User $participant, TrainingWeek $trainingWeek, Activity $activity): array
    {
        $workspace = $this->weekThree->workspace($participant, $trainingWeek)->load(['findings.tests.fixValidation']);
        $stage = max(1, min(3, $activity->position));
        $paths = $this->weekThree->requiredPaths($participant);
        $tests = $workspace->findings->flatMap->tests;
        $counts = [
            'Findings' => $workspace->findings->filter(fn (WeekThreeFinding $finding): bool => filled($finding->title))->count(),
            'Tests' => $tests->count(),
            'Fix Validations' => $tests->filter(fn (WeekThreeTest $test): bool => $test->fixValidation !== null)->count(),
        ];

        $findings = [];
        foreach (range(0, 4) as $index) {
            /** @var ?WeekThreeFinding $finding */
            $finding = $workspace->findings->get($index);
            $findings[] = [
                'number' => $index + 1,
                'finding' => $finding,
                'fields' => $stage === 1 ? $this->weekThreeFindingFields($index, $finding) : [],
                'tests' => $stage === 1 ? [] : array_map(
                    fn (string $path): array => $this->weekThreeTest($index, $path, $finding?->tests->firstWhere('path', $path), $stage === 3),
                    $paths,
                ),
            ];
        }

        $regressionFields = [];
        if ($stage === 3) {
            foreach (self::WEEK_THREE_REGRESSION_FIELDS as $key => $label) {
                $regressionFields[] = $this->weekThreeField($key, $label, true, 4, $workspace->{$key});
            }
        }

        return [
            'stage' => $stage,
            'progress' => array_map(fn (string $label, int $count): array => ['label' => $label, 'count' => $count, 'is_complete' => $count >= 5], array_keys($counts), array_values($counts)),
            'findings' => $findings,
            'regression_fields' => $regressionFields,
        ];
    }

    /**
     * The Activity 1 fields of a finding slot, filled from the saved finding.
     *
     * @return list<array<string, mixed>>
     */
    private function weekThreeFindingFields(int $index, ?WeekThreeFinding $finding): array
    {
        $fields = [];
        foreach (self::WEEK_THREE_FINDING_FIELDS as $key => [$label, $isRequired, $rows]) {
            $isSelect = $key === 'human_validation';
            $fields[] = $this->weekThreeField(
                "findings[{$index}][{$key}]",
                $label,
                $isRequired,
                $rows,
                $finding?->{$key},
                options: $isSelect ? self::WEEK_THREE_HUMAN_VALIDATIONS : null,
                placeholder: $isSelect ? 'Select an assessment' : '',
            );
        }

        return $fields;
    }

    /**
     * The test of a finding on one path: its fields, and when fix validation applies and the test is saved, the fix
     * validation fields.
     *
     * @return array{heading: string, fields: list<array<string, mixed>>, fix_fields: list<array<string, mixed>>}
     */
    private function weekThreeTest(int $index, string $path, ?WeekThreeTest $test, bool $validatesFix): array
    {
        $prefix = "findings[{$index}][tests][{$path}]";
        $fields = [];
        foreach ([...self::WEEK_THREE_TEST_FIELDS, ...self::WEEK_THREE_PATH_TEST_FIELDS[$path]] as $key => [$label, $rows]) {
            $fields[] = $this->weekThreeField("{$prefix}[{$key}]", $label, true, $rows, $test?->{$key});
        }

        $fixFields = [];
        if ($validatesFix && $test) {
            $fix = $test->fixValidation;
            foreach (self::WEEK_THREE_FIX_FIELDS as $key => $label) {
                $fixFields[] = $this->weekThreeField("{$prefix}[fix_validation][{$key}]", $label, true, 3, $fix?->{$key});
            }
            $fixFields[] = $this->weekThreeField("{$prefix}[fix_validation][status]", 'Fix validation status', true, 1, $fix?->status, self::WEEK_THREE_FIX_STATUSES, 'Select status');
        }

        return ['heading' => ucfirst($path).' test', 'fields' => $fields, 'fix_fields' => $fixFields];
    }

    /**
     * A Week 3 workspace control: its input name with the matching old-input key and element id, the label and required
     * marker, the textarea rows, the saved value, and for a select its options and placeholder option.
     *
     * @param  ?array<string, string>  $options
     * @return array{id: string, name: string, old_key: string, label: string, is_required: bool, rows: int, value: string, options: ?array<string, string>, placeholder: string}
     */
    private function weekThreeField(string $name, string $label, bool $isRequired, int $rows, ?string $value, ?array $options = null, string $placeholder = ''): array
    {
        $oldKey = str_replace(['][', '[', ']'], ['.', '.', ''], $name);

        return [
            'id' => 'week-three-'.str_replace(['.', '_'], '-', $oldKey),
            'name' => $name,
            'old_key' => $oldKey,
            'label' => $label,
            'is_required' => $isRequired,
            'rows' => $rows,
            'value' => $value ?? '',
            'options' => $options,
            'placeholder' => $placeholder,
        ];
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
