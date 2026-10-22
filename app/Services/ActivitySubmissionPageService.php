<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Enums\SubmissionStatus;
use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
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
        private readonly GoogleDriveConfigurationService $googleConfiguration,
        private readonly SubmissionAnswerPresenter $answers,
        private readonly WeekFourReviewPresenter $weekFour,
        private readonly WeekThreeWorkspacePresenter $weekThreeWorkspace,
        private readonly WeekFourSubmissionPresenter $weekFourSubmission,
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
        $coachFeedback = $submission?->versions->flatMap->reviews->sortByDesc('reviewed_at')->firstWhere('decision', ReviewDecision::NeedsRevision);
        $weekFourInterview = $this->weekFourSubmission->interviewData($participant, $trainingWeek, $activity, $version);

        return [
            'user' => $participant,
            'trainingWeek' => $trainingWeek,
            'activity' => $activity,
            'submission' => $submission,
            'version' => $version,
            'isEditable' => ! $version || $version->isDraft(),
            'mainAction' => match (true) {
                $version?->isDraft() === true => 'submit',
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
            'versionRows' => $this->versionRows($submission?->versions ?? collect(), $version),
            'weekThreeWorkspace' => $trainingWeek->week_number === 3 ? $this->weekThreeWorkspace->present($participant, $trainingWeek, $activity) : null,
            ...$weekFourInterview,
            'weekFourGuide' => $trainingWeek->week_number === 4 ? $this->weekFourSubmission->guide($activity, $version, $weekFourInterview['partnerFeedback']) : null,
            'participantCalendarEnabled' => $this->googleConfiguration->participantCalendarEnabled(),
        ];
    }

    /**
     * Data for a submitted version: the status summary, the labelled answers, the participant's own Lab record, the
     * evidence, the coach reviews with the published score and its dimension rows, the Week 3 and Week 4 review blocks,
     * and the submitted version history.
     *
     * @return array{pathLabel: string, statusLabel: string, statusTone: string, responses: Collection<int|string, array{label: string, value: mixed, text: mixed, markdown: bool}>, labRecord: ?ValidationSubmission, evidenceItems: Collection<int, array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, reviewRows: list<array{decision_label: string, tone: string, comments: ?string}>, finalScore: ?ActivityScore, dimensionRows: list<array{dimension: string, points: int, evidence_reasoning: string}>, isAwaitingRevision: bool, weekThreeReview: ?array<string, mixed>, weekFourReview: ?array<string, mixed>, weekFourTitle: ?string, versionRows: list<array{version: SubmissionVersion, status_label: string, tone: string, is_current: bool}>}
     */
    public function showData(User $participant, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $version): array
    {
        $configured = collect($this->workflow->fieldDefinitions($activity, $participant))->mapWithKeys(fn (array $field): array => [$field['key'] => $field['label']])->all();
        $responses = collect($version->field_values ?? [])->except(['week_three_snapshot'])->map(fn (mixed $value, int|string $key): array => [
            'label' => $configured[$key] ?? self::LEGACY_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'value' => $value,
            'text' => is_scalar($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'markdown' => in_array($key, Markdown::OUTPUT_FIELDS, true),
        ]);
        $finalScore = $version->activityScore?->isPublished() ? $version->activityScore : null;
        $weekThreeSnapshot = $trainingWeek->week_number === 3 ? ($version->field_values['week_three_snapshot'] ?? null) : null;
        $weekFourReview = $this->weekFour->present($activity, $version->field_values ?? []);
        $versions = $version->submission->versions;

        return [
            'pathLabel' => ucfirst($version->submission->participant->training_path?->value ?? 'QA'),
            'statusLabel' => $this->statusLabel($version->status),
            'statusTone' => $this->statusTone($version->status),
            'responses' => $responses,
            'labRecord' => $version->validationSubmission?->user_id === $participant->id ? $version->validationSubmission : null,
            'evidenceItems' => $this->evidenceItems($version->evidence),
            'reviewRows' => $version->reviews->map(fn (SubmissionReview $review): array => [
                'decision_label' => $this->statusLabel($review->decision),
                'tone' => $this->statusTone($review->decision),
                'comments' => $review->comments,
            ])->values()->all(),
            'finalScore' => $finalScore,
            'dimensionRows' => $this->dimensionRows($finalScore),
            'isAwaitingRevision' => $version->submission->status === 'needs_revision',
            'weekThreeReview' => $weekThreeSnapshot ? $this->answers->weekThreeReview($weekThreeSnapshot, (int) $activity->position, $version->submission->participant->training_path) : null,
            'weekFourReview' => $weekFourReview,
            'weekFourTitle' => $weekFourReview ? match ($activity->position) {
                1 => 'Reusable AI Asset',
                2 => 'Mock Level 3 Interview',
                default => 'Strict-mode Leveling Assessment',
            } : null,
            'versionRows' => $versions->count() > 1 ? $this->versionRows($versions->where('status', '!=', 'draft')->sortByDesc('version_number'), $version) : [],
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
        return ValidationSubmission::query()->where('user_id', $participant->id)->whereIn('status', ValidationSubmissionStatus::recorded())->latest('submitted_at')->latest('id')->get();
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
                'evidence' => $this->evidenceItems($evidence->where('evidence_requirement_id', $requirement->id)),
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
            $checks[] = ['label' => $label, 'status' => strtoupper($record->{$status}?->value ?? 'Not provided'), 'notes' => $record->{$notes} ?: 'Not provided'];
        }

        return $checks;
    }

    /**
     * The evidence items with whether each is an uploaded file and the type label to show.
     *
     * @param  Collection<int, SubmissionEvidence>  $evidence
     * @return Collection<int, array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>
     */
    private function evidenceItems(Collection $evidence): Collection
    {
        return $evidence->map(function (SubmissionEvidence $item): array {
            $isFile = filled($item->disk) && filled($item->path) && filled($item->original_filename);

            return ['evidence' => $item, 'is_file' => $isFile, 'type_label' => $isFile ? 'Uploaded File' : ucfirst($item->evidence_type)];
        })->values()->toBase();
    }

    /**
     * The published score's dimension rows (D1 to D5, in order) that were scored.
     *
     * @return list<array{dimension: string, points: int, evidence_reasoning: string}>
     */
    private function dimensionRows(?ActivityScore $score): array
    {
        $dimensions = $score?->dimensions->keyBy('dimension') ?? collect();

        return collect(array_keys(ScoringService::DIMENSIONS))
            ->filter(fn (string $dimension): bool => $dimensions->has($dimension))
            ->map(fn (string $dimension): array => [
                'dimension' => $dimension,
                'points' => $dimensions->get($dimension)->points,
                'evidence_reasoning' => $dimensions->get($dimension)->evidence_reasoning,
            ])
            ->values()
            ->all();
    }

    /**
     * The given versions in order, with each version's status label and tone and whether it is the one being shown.
     *
     * @param  Collection<int, SubmissionVersion>  $versions
     * @return list<array{version: SubmissionVersion, status_label: string, tone: string, is_current: bool}>
     */
    private function versionRows(Collection $versions, ?SubmissionVersion $version): array
    {
        return $versions->map(fn (SubmissionVersion $historic): array => [
            'version' => $historic,
            'status_label' => $this->statusLabel($historic->status),
            'tone' => $this->statusTone($historic->status),
            'is_current' => $version !== null && $historic->is($version),
        ])->values()->all();
    }

    /**
     * A version status or review decision as a label: `NeedsRevision` becomes "Needs revision".
     */
    private function statusLabel(SubmissionStatus|ReviewDecision $status): string
    {
        return $status instanceof SubmissionStatus ? $status->label() : ucfirst(str_replace('_', ' ', $status->value));
    }

    /**
     * The badge tone of a version status or review decision.
     */
    private function statusTone(SubmissionStatus|ReviewDecision $status): string
    {
        return match ($status) {
            SubmissionStatus::Approved, SubmissionStatus::Completed, ReviewDecision::Approved => 'success',
            SubmissionStatus::Failed, ReviewDecision::Rejected => 'danger',
            SubmissionStatus::NeedsRevision, ReviewDecision::NeedsRevision => 'warning',
            default => 'neutral',
        };
    }
}
