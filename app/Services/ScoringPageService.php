<?php

namespace App\Services;

use App\Models\ActivityScore;
use App\Models\ActivityScoreSuggestion;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ScoringPageService
{
    /** The AI scoring status under which suggestions can be generated and feedback improved. */
    private const AI_CONFIGURED = 'Configured';

    public function __construct(
        private readonly ScoringService $scoring,
        private readonly SubmissionAnswerPresenter $answers,
        private readonly AiProviderConfigurationService $configuration,
    ) {}

    /**
     * Everything the coach scoring page of a submission version renders: the header, the immutable submission (answers,
     * Week 3 checkpoint, prompt / output history, evidence), the AI suggestions, the scoring fields with their values
     * (a suggestion draft kept from the page, otherwise the saved score, otherwise the suggestion when nothing is saved
     * yet), the live score context and the second-reader review. The single main action is Save / Publish Score.
     *
     * @param  array<string, mixed>|null  $draft  The scoring form values flashed when a suggestion was generated.
     * @return array{version: SubmissionVersion, activityTitle: string, participantName: string, weekNumber: int, submissionStatus: string, submittedAt: string, answers: list<array{label: string, text: mixed}>, weekThreeCheckpoint: ?array{findings: list<array{heading: string, description: mixed, actual_result: mixed, human_validation: mixed, tests: list<array{label: string, name: mixed, execution_result: mixed, fix_validation: ?array{status: mixed, bug_free_result: mixed}}>}>, conclusion: ?array{final_conclusion: mixed, coverage_gaps: mixed}}, evidenceRows: list<array{title: string, detail: ?string}>, suggestion: ?ActivityScoreSuggestion, suggestionRows: list<array{heading: string, feedback: mixed, evidence_basis: mixed}>, evidenceProcessing: list<string>, suggestionData: ?array{dimensions: mixed, overall_feedback: ?string}, aiStatus: string, isAiConfigured: bool, canManage: bool, scoreFields: list<array{key: string, label: string, max: int, evidence_id: string, points_id: string, points: mixed, evidence_reasoning: mixed}>, overallFeedback: mixed, previousAverage: ?int, score: ?ActivityScore, canManageSecondReader: bool, readers: Collection<int, User>}
     */
    public function editData(SubmissionVersion $version, User $viewer, ?array $draft): array
    {
        $version->loadMissing(['submission.participant', 'submission.activity.week', 'evidence', 'activityScore.dimensions', 'scoreSuggestion', 'interactions']);
        $submission = $version->submission;
        $score = $version->activityScore;
        $suggestion = $version->scoreSuggestion;
        $canManage = $viewer->canManageParticipant($submission->participant);
        $canManageSecondReader = $canManage && (bool) $score?->second_reader_required;
        $aiStatus = $this->configuration->adminValues()['status'];

        return [
            'version' => $version,
            'activityTitle' => $submission->activity->title,
            'participantName' => $submission->participant->name,
            'weekNumber' => $submission->activity->week->week_number,
            'submissionStatus' => $submission->status,
            'submittedAt' => $version->submitted_at?->format('M j, Y g:i A') ?? '—',
            'answers' => $this->answerRows($version),
            'weekThreeCheckpoint' => $this->weekThreeCheckpoint($version),
            'evidenceRows' => $this->evidenceRows($version->evidence),
            'suggestion' => $suggestion,
            'suggestionRows' => $this->suggestionRows($suggestion),
            'evidenceProcessing' => $this->evidenceProcessing($suggestion),
            'suggestionData' => $suggestion ? ['dimensions' => $suggestion->dimensions, 'overall_feedback' => $suggestion->overall_feedback] : null,
            'aiStatus' => $aiStatus,
            'isAiConfigured' => $aiStatus === self::AI_CONFIGURED,
            'canManage' => $canManage,
            'scoreFields' => $this->scoreFields($score, $suggestion, $draft),
            'overallFeedback' => $this->overallFeedback($score, $suggestion, $draft),
            'previousAverage' => $this->scoring->runningAverage($submission->participant, $version->id),
            'score' => $score,
            'canManageSecondReader' => $canManageSecondReader,
            'readers' => $canManageSecondReader
                ? User::query()->whereIn('role', ['coach', 'admin'])->where('is_active', true)->orderBy('name')->get()
                : new Collection,
        ];
    }

    /**
     * The submitted answers with the text to show: a scalar value as is, anything else as pretty-printed JSON.
     *
     * @return list<array{label: string, text: mixed}>
     */
    private function answerRows(SubmissionVersion $version): array
    {
        return collect($this->answers->fields($version))
            ->map(fn (array $field): array => [
                'label' => $field['label'],
                'text' => is_scalar($field['value']) ? $field['value'] : json_encode($field['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ])
            ->values()
            ->all();
    }

    /**
     * The Week 3 workspace checkpoint saved with a Week 3 version (findings with their tests, and the regression
     * conclusion), or null on other weeks or without a snapshot.
     *
     * @return ?array{findings: list<array{heading: string, description: mixed, actual_result: mixed, human_validation: mixed, tests: list<array{label: string, name: mixed, execution_result: mixed, fix_validation: ?array{status: mixed, bug_free_result: mixed}}>}>, conclusion: ?array{final_conclusion: mixed, coverage_gaps: mixed}}
     */
    private function weekThreeCheckpoint(SubmissionVersion $version): ?array
    {
        $snapshot = $version->submission->activity->week->week_number === 3 ? ($version->field_values['week_three_snapshot'] ?? null) : null;

        if (! $snapshot) {
            return null;
        }

        $findings = collect($snapshot['findings'] ?? [])->values()->map(fn (array $finding, int $index): array => [
            'heading' => 'Finding '.($finding['position'] ?? $index + 1).' · '.($finding['title'] ?? 'Untitled'),
            'description' => $finding['description'] ?? '',
            'actual_result' => $finding['actual_result'] ?? '',
            'human_validation' => $finding['human_validation'] ?? '',
            'tests' => collect($finding['tests'] ?? [])->map(fn (array $test): array => [
                'label' => ucfirst($test['path'] ?? 'Test').' test:',
                'name' => $test['name'] ?? '',
                'execution_result' => $test['execution_result'] ?? '',
                'fix_validation' => empty($test['fix_validation']) ? null : [
                    'status' => $test['fix_validation']['status'] ?? '',
                    'bug_free_result' => $test['fix_validation']['bug_free_result'] ?? '',
                ],
            ])->values()->all(),
        ]);

        return [
            'findings' => $findings->all(),
            'conclusion' => ($snapshot['final_conclusion'] ?? null)
                ? ['final_conclusion' => $snapshot['final_conclusion'], 'coverage_gaps' => $snapshot['coverage_gaps'] ?? '']
                : null,
        ];
    }

    /**
     * The evidence items, each titled by its title or type, with its text, link or file name.
     *
     * @param  Collection<int, SubmissionEvidence>  $evidence
     * @return list<array{title: string, detail: ?string}>
     */
    private function evidenceRows(Collection $evidence): array
    {
        return $evidence->map(fn (SubmissionEvidence $item): array => [
            'title' => $item->title ?: $item->evidence_type,
            'detail' => $item->content ?: $item->url ?: $item->original_filename,
        ])->values()->all();
    }

    /**
     * The AI-suggested score and feedback for each dimension that has a suggestion, in rubric order.
     *
     * @return list<array{heading: string, feedback: mixed, evidence_basis: mixed}>
     */
    private function suggestionRows(?ActivityScoreSuggestion $suggestion): array
    {
        if (! $suggestion) {
            return [];
        }

        return collect(ScoringService::DIMENSIONS)
            ->filter(fn (array $dimension, string $key): bool => (bool) ($suggestion->dimensions[$key] ?? null))
            ->map(fn (array $dimension, string $key): array => [
                'heading' => "{$key} · {$dimension['label']} — Suggested {$suggestion->dimensions[$key]['suggested_score']}/{$dimension['max']}",
                'feedback' => $suggestion->dimensions[$key]['feedback'],
                'evidence_basis' => empty($suggestion->dimensions[$key]['evidence_basis']) ? null : $suggestion->dimensions[$key]['evidence_basis'],
            ])
            ->values()
            ->all();
    }

    /**
     * One line per evidence file the suggestion considered: its name, processing status and strategy.
     *
     * @return list<string>
     */
    private function evidenceProcessing(?ActivityScoreSuggestion $suggestion): array
    {
        return collect($suggestion?->evidence_processing ?: [])
            ->map(function (array $status): string {
                $line = ($status['filename'] ?? 'Evidence').' ? '.str_replace('_', ' ', $status['status'] ?? 'unknown');

                return empty($status['strategy']) ? $line : $line.' ('.str_replace('_', ' ', $status['strategy']).')';
            })
            ->values()
            ->all();
    }

    /**
     * The scoring fields of each rubric dimension with their values: the draft kept while generating a suggestion,
     * otherwise the saved score, otherwise (only while nothing is saved) the AI suggestion.
     *
     * @param  array<string, mixed>|null  $draft
     * @return list<array{key: string, label: string, max: int, evidence_id: string, points_id: string, points: mixed, evidence_reasoning: mixed}>
     */
    private function scoreFields(?ActivityScore $score, ?ActivityScoreSuggestion $suggestion, ?array $draft): array
    {
        $draftDimensions = is_array($draft['dimensions'] ?? null) ? $draft['dimensions'] : [];
        $suggestedDimensions = $suggestion?->dimensions ?? [];

        return collect(ScoringService::DIMENSIONS)->map(function (array $dimension, string $key) use ($score, $draftDimensions, $suggestedDimensions): array {
            $existing = $score?->dimensions->firstWhere('dimension', $key);
            $draftValue = array_key_exists($key, $draftDimensions) ? ($draftDimensions[$key] ?? []) : null;
            $suggested = $suggestedDimensions[$key] ?? [];

            return [
                'key' => $key,
                'label' => $dimension['label'],
                'max' => $dimension['max'],
                'evidence_id' => strtolower($key).'_evidence',
                'points_id' => strtolower($key).'_points',
                'points' => $draftValue !== null
                    ? ($draftValue['points'] ?? null)
                    : ($existing?->points ?? ($score === null ? ($suggested['suggested_score'] ?? null) : null)),
                'evidence_reasoning' => $draftValue !== null
                    ? ($draftValue['evidence_reasoning'] ?? '')
                    : ($existing?->evidence_reasoning ?? ($score === null ? ($suggested['feedback'] ?? '') : '')),
            ];
        })->values()->all();
    }

    /**
     * The overall feedback value: the draft's, otherwise the saved score's, otherwise (only while nothing is saved)
     * the AI suggestion's.
     *
     * @param  array<string, mixed>|null  $draft
     */
    private function overallFeedback(?ActivityScore $score, ?ActivityScoreSuggestion $suggestion, ?array $draft): mixed
    {
        if (array_key_exists('overall_feedback', $draft ?? [])) {
            return $draft['overall_feedback'] ?? '';
        }

        return $score?->overall_feedback ?? ($score === null ? ($suggestion?->overall_feedback ?? '') : '');
    }
}
