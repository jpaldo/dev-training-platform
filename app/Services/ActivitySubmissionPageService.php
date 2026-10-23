<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Enums\SubmissionStatus;
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
use App\Support\FieldLimits;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ActivitySubmissionPageService
{
    /** @var array<string, string> Labels for answer keys that are no longer configured as submission fields. */
    private const LEGACY_LABELS = ['thread_drive_link' => 'Thread / Drive Link', 'ai_tool' => 'AI Tool', 'artifact_type' => 'Artifact Type', 'prompt' => 'Prompt', 'exact_ai_output' => 'Exact AI Output', 'ai_output' => 'AI Output', 'pre_acceptance_question' => 'Pre-acceptance Question', 'validation_performed' => 'Validation Performed'];

    public function __construct(
        private readonly SubmissionWorkflowService $workflow,
        private readonly SubmissionAnswerPresenter $answers,
        private readonly SafeMarkdownRenderer $markdown,
        private readonly EvidenceFilePolicy $evidenceFiles,
    ) {}

    /**
     * Data for the submission form: the latest attempt and version, the main action, the coach feedback, the field
     * sections and evidence rows, and the version history.
     *
     * @return array<string, mixed>
     */
    public function editData(User $participant, TrainingWeek $trainingWeek, Activity $activity): array
    {
        $submission = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $participant->id)->with(['versions' => fn (Relation $query): Relation => $query->with(['evidence', 'reviews', 'interactions'])->orderByDesc('version_number')])->orderByDesc('attempt_number')->first();
        $version = $submission?->versions->first();
        $fields = $this->workflow->fieldDefinitions($activity);
        $showInteractions = collect($fields)->keys()->intersect(['prompt', 'exact_prompt', 'ai_output', 'exact_ai_output'])->isNotEmpty();
        /** @var ?SubmissionReview $coachFeedback */
        $coachFeedback = $submission?->versions->flatMap->reviews->sortByDesc('reviewed_at')->firstWhere('decision', ReviewDecision::NeedsRevision);

        return [
            'user' => $participant,
            'trainingWeek' => $trainingWeek,
            'activity' => $activity,
            'submission' => $submission,
            'version' => $version,
            'isEditable' => ! $version || $version->isDraft(),
            'mainAction' => match (true) {
                $version?->isDraft() === true => 'submit',
                $submission?->needsRevision() === true => 'revise',
                default => null,
            },
            'coachFeedback' => $coachFeedback?->comments ? $this->markdown->render($coachFeedback->comments) : null,
            'showInteractions' => $showInteractions,
            'interactionRows' => $showInteractions ? $this->interactionRows($version) : [],
            'fieldSections' => $this->fieldSections($fields),
            'artifactTypes' => config('training.artifact_types'),
            'evidenceRows' => $this->evidenceRows($this->workflow->evidenceRequirements($activity), $version),
            'versionRows' => $this->versionRows($submission?->versions ?? collect(), $version),
        ];
    }

    /**
     * Data for a submitted version: the status summary, the labelled answers, the evidence, the coach reviews with the
     * published score and its dimension rows, and the submitted version history.
     *
     * @return array{statusLabel: string, statusTone: string, responses: Collection<int|string, array{label: string, value: mixed, text: mixed}>, evidenceItems: Collection<int, array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, reviewRows: list<array{decision_label: string, tone: string, comments: ?string}>, finalScore: ?ActivityScore, dimensionRows: list<array{dimension: string, points: int, evidence_reasoning: string}>, isAwaitingRevision: bool, versionRows: list<array{version: SubmissionVersion, status_label: string, tone: string, is_current: bool}>}
     */
    public function showData(Activity $activity, SubmissionVersion $version): array
    {
        $configured = collect($this->workflow->fieldDefinitions($activity))->mapWithKeys(fn (array $field): array => [$field['key'] => $field['label']])->all();
        $responses = collect($version->field_values ?? [])->map(fn (mixed $value, int|string $key): array => [
            'label' => $configured[$key] ?? self::LEGACY_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'value' => $value,
            'text' => is_scalar($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $finalScore = $version->publishedActivityScore;
        $versions = $version->submission->versions;

        return [
            'statusLabel' => $version->status->label(),
            'statusTone' => $version->status->tone(),
            'responses' => $responses,
            'evidenceItems' => $this->answers->evidenceItems($version->evidence),
            'reviewRows' => $version->reviews->map(fn (SubmissionReview $review): array => [
                'decision_label' => $review->decision->label(),
                'tone' => $review->decision->tone(),
                'comments' => $review->comments,
            ])->values()->all(),
            'finalScore' => $finalScore,
            'dimensionRows' => $this->dimensionRows($activity, $finalScore),
            'isAwaitingRevision' => $version->submission->needsRevision(),
            'versionRows' => $versions->count() > 1 ? $this->versionRows($versions->where('status', '!=', SubmissionStatus::Draft)->sortByDesc('version_number'), $version) : [],
        ];
    }

    /**
     * The form fields grouped under their section headings: the AI / artifact context section opens before its first
     * field and the activity work section before the first other field, each with the control to render, its placeholder
     * (`https://github.com/` for a GitHub link) and its length limit.
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
                $sections[] = ['title' => '1. AI / ARTIFACT CONTEXT', 'description' => 'Document the AI artifact you are working with before recording your activity-specific work.', 'fields' => []];
                $artifactContextShown = true;
            } elseif (! $isArtifactContext && ! $activityWorkShown) {
                $sections[] = ['title' => ($artifactContextShown ? '2.' : '1.').' YOUR WORK', 'description' => 'Complete the activity-specific questions in the configured workflow order.', 'fields' => []];
                $activityWorkShown = true;
            }

            $sections[array_key_last($sections)]['fields'][] = [
                ...$field,
                'key' => $key,
                'control' => $this->fieldControl($key, $field),
                'placeholder' => ($field['type'] ?? null) === 'github_url' ? 'https://github.com/' : null,
                'max_length' => FieldLimits::forField($key, $field['type'] ?? 'textarea'),
                'has_length_note' => in_array($key, ['exact_prompt', 'exact_ai_output', 'ai_output', 'prompt'], true),
            ];
        }

        return $sections;
    }

    /**
     * The control a form field renders as: date, artifact type select, select, URL (also for a GitHub link), text,
     * Markdown editor with preview, or plain textarea.
     *
     * @param  array<string, mixed>  $field
     */
    private function fieldControl(string $key, array $field): string
    {
        $type = $field['type'] ?? 'textarea';

        return match (true) {
            $type === 'date' => 'date',
            $key === 'artifact_type' => 'artifact_type',
            $type === 'github_url' => 'url',
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
     * One row per evidence requirement: the version's evidence for it, the input (text, link or file), the link's label
     * and hint, whether it takes files (every type but `video`, which is a link only) and the file upload label,
     * accepted file types and size hint.
     *
     * @param  Collection<int, EvidenceRequirement>  $requirements
     * @return Collection<int, array{requirement: EvidenceRequirement, evidence: Collection<int, array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, input: string, link_label: string, link_hint: ?string, accepts_files: bool, file_label: string, accept: string, size_hint: string, is_reusable_asset: bool}>
     */
    private function evidenceRows(Collection $requirements, ?SubmissionVersion $version): Collection
    {
        $evidence = $version?->evidence ?? collect();

        return $requirements->map(function (EvidenceRequirement $requirement) use ($evidence): array {
            $isVideo = $requirement->evidence_type === 'video';
            $isReusableAsset = $requirement->evidence_type === 'asset';
            $input = match (true) {
                $requirement->evidence_type === 'text' => 'text',
                in_array($requirement->evidence_type, ['link', 'url', 'video', 'repository'], true) => 'link',
                default => 'file',
            };

            return [
                'requirement' => $requirement,
                'evidence' => $this->answers->evidenceItems($evidence->where('evidence_requirement_id', $requirement->id)),
                'input' => $input,
                'link_label' => $isVideo ? 'Recording link' : 'URL or recording link',
                'link_hint' => $isVideo ? 'Paste a link to your recording, for example from Google Drive, Loom or YouTube. Make sure your mentor can open it.' : null,
                'accepts_files' => ! $isVideo,
                'file_label' => match ($input) {
                    'text' => 'Or upload artifact',
                    'link' => 'Or upload file',
                    default => 'File',
                },
                'accept' => $this->evidenceFiles->acceptAttribute(),
                'size_hint' => 'You can select and upload multiple files. Maximum file size: 300 MB per file.',
                'is_reusable_asset' => $isReusableAsset,
            ];
        })->values();
    }

    /**
     * The published score's dimension rows in the activity's rubric order; a scored dimension the activity no longer
     * has follows, by key.
     *
     * @return list<array{dimension: string, points: int, evidence_reasoning: string}>
     */
    private function dimensionRows(Activity $activity, ?ActivityScore $score): array
    {
        $rubricOrder = array_flip(array_column($activity->scoringDimensions(), 'key'));

        return ($score?->dimensions ?? collect())
            ->sortBy([
                fn (ActivityScoreDimension $first, ActivityScoreDimension $second): int => ($rubricOrder[$first->dimension] ?? PHP_INT_MAX) <=> ($rubricOrder[$second->dimension] ?? PHP_INT_MAX),
                fn (ActivityScoreDimension $first, ActivityScoreDimension $second): int => $first->dimension <=> $second->dimension,
            ])
            ->map(fn (ActivityScoreDimension $dimension): array => [
                'dimension' => $dimension->dimension,
                'points' => $dimension->points,
                'evidence_reasoning' => $dimension->evidence_reasoning,
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
            'status_label' => $historic->status->label(),
            'tone' => $historic->status->tone(),
            'is_current' => $version !== null && $historic->is($version),
        ])->values()->all();
    }
}
