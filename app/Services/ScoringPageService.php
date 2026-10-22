<?php

namespace App\Services;

use App\Enums\ActivityScoreStatus;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\ActivityScore;
use App\Models\ActivityScoreSuggestion;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ScoringPageService
{
    /** The AI scoring status under which suggestions can be generated and feedback improved. */
    private const AI_CONFIGURED = 'Configured';

    private const PARTICIPANTS_PER_PAGE = 25;

    /** The scored weeks, in column order on the scoring overview. */
    private const SCORED_WEEKS = [2, 3, 4];

    private const PATH_OPTIONS = [TrainingPath::Manual->value => 'Manual QA', TrainingPath::Automation->value => 'Automation QA'];

    private const SCORE_STATUS_OPTIONS = ['scored' => 'Scored', 'unscored' => 'Unscored'];

    private const BAND_OPTIONS = ['Exemplary' => 'Exemplary', 'Proficient' => 'Proficient', 'Developing' => 'Developing', 'Beginning' => 'Beginning', 'Minimal' => 'Minimal'];

    /** The band a submitted version without a score is counted under in the scoring reports' band distribution. */
    private const NOT_SCORED_BAND = 'Not Scored';

    /** The points range of each performance band per rubric dimension, shown on the scoring criteria page. */
    private const DIMENSION_BAND_RANGES = [
        'D1' => ['Exemplary' => '27–30', 'Proficient' => '24–26', 'Developing' => '18–23', 'Beginning' => '11–17', 'Minimal' => '1–10'],
        'D2' => ['Exemplary' => '23–25', 'Proficient' => '20–22', 'Developing' => '15–19', 'Beginning' => '9–14', 'Minimal' => '1–8'],
        'D3' => ['Exemplary' => '18–20', 'Proficient' => '16–17', 'Developing' => '12–15', 'Beginning' => '7–11', 'Minimal' => '1–6'],
        'D4' => ['Exemplary' => '14–15', 'Proficient' => '12–13', 'Developing' => '9–11', 'Beginning' => '6–8', 'Minimal' => '1–5'],
        'D5' => ['Exemplary' => '9–10', 'Proficient' => '8', 'Developing' => '6–7', 'Beginning' => '4–5', 'Minimal' => '1–3'],
    ];

    public function __construct(
        private readonly ScoringService $scoring,
        private readonly SubmissionAnswerPresenter $answers,
        private readonly AiProviderConfigurationService $configuration,
        private readonly ScoringReportService $reports,
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
                ? User::query()->whereIn('role', [UserRole::Coach, UserRole::Admin])->where('is_active', true)->orderBy('name')->get()
                : new Collection,
        ];
    }

    /**
     * Everything the scoring overview renders: the filter values and options and the matching participants with
     * their Weeks 2–4 and overall score and completion. The page has no single main action, so every button is
     * secondary.
     *
     * @param  array{search?: ?string, path?: ?string, week?: int|string|null, activity?: int|string|null, score_status?: ?string, band?: ?string, assigned_to_me?: bool|int|string|null}  $filters
     * @return array{criteriaUrl: string, reportsUrl: string, search: string, pathOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, scoreStatusOptions: list<array{value: string, label: string, is_selected: bool}>, bandOptions: list<array{value: string, label: string, is_selected: bool}>, isAssignedToMe: bool, weekNumbers: list<int>, participants: LengthAwarePaginator<int, array{name: string, email: string, path: string, weeks: list<array{average: ?int, band: ?string, completion: string}>, average: string, completion: string, coach_names: string, scoring_url: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        $program = $this->activeProgram();

        return [
            'criteriaUrl' => route('coach.scoring.criteria'),
            'reportsUrl' => route('coach.scoring.reports'),
            'search' => (string) ($filters['search'] ?? ''),
            'pathOptions' => $this->options(self::PATH_OPTIONS, $filters['path'] ?? null),
            'weekOptions' => $this->weekOptions($filters['week'] ?? null),
            'scoreStatusOptions' => $this->options(self::SCORE_STATUS_OPTIONS, $filters['score_status'] ?? null),
            'bandOptions' => $this->options(self::BAND_OPTIONS, $filters['band'] ?? null),
            'isAssignedToMe' => (bool) ($filters['assigned_to_me'] ?? false),
            'weekNumbers' => self::SCORED_WEEKS,
            'participants' => $this->filteredParticipants($viewer, $filters)
                ->through(fn (User $participant): array => $this->overviewRow($participant, $program)),
        ];
    }

    /**
     * Everything the participant scoring page renders: the overall score and completion, and per scored week its
     * average, completion and the activity rows with the link to score (or view the score of) each submitted version.
     *
     * @return array{participantName: string, participantEmail: string, path: string, stats: list<array{label: string, value: string, note: ?string}>, weeks: list<array{number: int, heading: string, detail: string, rows: list<array{activity: string, score: string, band: string, status: string, action: ?array{label: string, url: string}}>}>}
     */
    public function participantData(User $participant): array
    {
        $summary = $this->scoring->summary($participant, $this->activeProgram());

        return [
            'participantName' => $participant->name,
            'participantEmail' => $participant->email,
            'path' => $this->pathLabel($participant),
            'stats' => [
                ['label' => 'OVERALL SCORE', 'value' => ($summary['average'] ?? '—').' / 100', 'note' => null],
                [
                    'label' => 'OVERALL COMPLETION',
                    'value' => "{$summary['completed']} / {$summary['total']}",
                    'note' => $this->completionPercent($summary['completed'], $summary['total'])."% complete · {$summary['scored']} scored",
                ],
            ],
            'weeks' => $summary['weeks']
                ->map(fn (array $week, int $weekNumber): array => [
                    'number' => $weekNumber,
                    'heading' => $week['average'] !== null ? "{$week['average']} / 100 average" : 'Average not yet scored',
                    'detail' => "Completion: {$this->completion($week['completed'], $week['total'])} · Scored: {$week['scored']} / {$week['total']}",
                    'rows' => $week['rows']->map(fn (array $row): array => [
                        'activity' => $row['activity']->title,
                        'score' => (string) ($row['score']?->total_score ?? '—'),
                        'band' => $row['score']?->performance_band ?? ($row['submission'] ? 'Awaiting scoring' : 'Not submitted'),
                        'status' => $row['submission']?->status ?? 'Not submitted',
                        'action' => $row['version'] && $row['version']->status !== 'draft'
                            ? ['label' => $row['score'] ? 'View score' : 'Score activity', 'url' => route('coach.scoring.edit', $row['version'])]
                            : null,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The scoring criteria page: each rubric dimension with its maximum and the points range of every band.
     *
     * @return array{dimensions: list<array{key: string, label: string, max: int, ranges: list<array{band: string, range: string}>}>}
     */
    public function criteriaData(): array
    {
        return [
            'dimensions' => collect(ScoringService::DIMENSIONS)
                ->map(fn (array $dimension, string $key): array => [
                    'key' => $key,
                    'label' => $dimension['label'],
                    'max' => $dimension['max'],
                    'ranges' => collect(self::DIMENSION_BAND_RANGES[$key])
                        ->map(fn (string $range, string $band): array => ['band' => $band, 'range' => $range])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Everything the scoring reports page renders: the two CSV export links (keeping the current query), the filter
     * options, the scoring completion counts of the submitted versions matching the filters and their score-band
     * distribution. The page has no single main action, so every button is secondary.
     *
     * @param  array{participant?: int|string|null, path?: ?string, week?: int|string|null, activity?: int|string|null, assigned_coach?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{scoresExportUrl: string, participantSummaryExportUrl: string, pathOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, scoreStatusOptions: list<array{value: string, label: string, is_selected: bool}>, bandOptions: list<array{value: string, label: string, is_selected: bool}>, stats: list<array{label: string, value: string}>, bandRows: list<array{band: string, count: int}>}
     */
    public function reportsData(array $filters, array $query): array
    {
        $versions = $this->reports->versions($filters)->get();
        $bandCounts = $versions->countBy(fn (SubmissionVersion $version): string => $version->activityScore?->performance_band ?? self::NOT_SCORED_BAND);

        return [
            'scoresExportUrl' => route('coach.reports.scores', $query),
            'participantSummaryExportUrl' => route('coach.reports.participant-summary', $query),
            'pathOptions' => $this->options(self::PATH_OPTIONS, $filters['path'] ?? null),
            'weekOptions' => $this->weekOptions($filters['week'] ?? null),
            'scoreStatusOptions' => $this->options(self::SCORE_STATUS_OPTIONS, $filters['score_status'] ?? null),
            'bandOptions' => $this->options(self::BAND_OPTIONS, $filters['band'] ?? null),
            'stats' => $this->scoringCompletionStats($versions),
            'bandRows' => collect([...array_keys(self::BAND_OPTIONS), self::NOT_SCORED_BAND])
                ->map(fn (string $band): array => ['band' => $band, 'count' => $bandCounts->get($band, 0)])
                ->all(),
        ];
    }

    /**
     * The scoring completion page: the completion counts of the submitted versions matching the filters.
     *
     * @param  array{participant?: int|string|null, path?: ?string, week?: int|string|null, activity?: int|string|null, assigned_coach?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{stats: list<array{label: string, value: string}>}
     */
    public function completionData(array $filters): array
    {
        return ['stats' => $this->scoringCompletionStats($this->reports->versions($filters)->get())];
    }

    private function activeProgram(): Program
    {
        return Program::query()->where('is_active', true)->firstOrFail();
    }

    /**
     * The participants matching the overview filters, 25 per page with the query string kept. "Assigned to me" only
     * narrows the list for a coach.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    private function filteredParticipants(User $viewer, array $filters): LengthAwarePaginator
    {
        return User::query()
            ->where('role', UserRole::Participant)
            ->when($filters['path'] ?? null, fn (Builder $query, string $path): Builder => $query->where('training_path', $path))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(
                fn (Builder $searchQuery): Builder => $searchQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"),
            ))
            ->when($filters['week'] ?? null, fn (Builder $query, int|string $week): Builder => $query->whereHas(
                'activitySubmissions.activity.week',
                fn (Builder $weekQuery): Builder => $weekQuery->where('week_number', $week),
            ))
            ->when($filters['activity'] ?? null, fn (Builder $query, int|string $activity): Builder => $query->whereHas(
                'activitySubmissions.activity',
                fn (Builder $activityQuery): Builder => $activityQuery->whereKey($activity),
            ))
            ->when(($filters['assigned_to_me'] ?? false) && $viewer->hasAnyRole(UserRole::Coach), fn (Builder $query): Builder => $query->whereHas(
                'assignedCoaches',
                fn (Builder $coachQuery): Builder => $coachQuery->whereKey($viewer->id),
            ))
            ->when(($filters['score_status'] ?? null) === 'scored', fn (Builder $query): Builder => $query->whereHas(
                'activitySubmissions.versions.activityScore',
                fn (Builder $scoreQuery): Builder => $scoreQuery->where('status', ActivityScoreStatus::Published),
            ))
            ->when(($filters['score_status'] ?? null) === 'unscored', fn (Builder $query): Builder => $query->whereDoesntHave('activitySubmissions.versions.activityScore'))
            ->when($filters['band'] ?? null, fn (Builder $query, string $band): Builder => $query->whereHas(
                'activitySubmissions.versions.activityScore',
                fn (Builder $scoreQuery): Builder => $scoreQuery->where('performance_band', $band),
            ))
            ->with('assignedCoaches')
            ->paginate(self::PARTICIPANTS_PER_PAGE)
            ->withQueryString();
    }

    /**
     * One overview row: the participant, each scored week's average with its band (null while unscored) and
     * completion, the overall average and completion, the assigned coaches and the link to the participant's scoring.
     *
     * @return array{name: string, email: string, path: string, weeks: list<array{average: ?int, band: ?string, completion: string}>, average: string, completion: string, coach_names: string, scoring_url: string}
     */
    private function overviewRow(User $participant, Program $program): array
    {
        $summary = $this->scoring->summary($participant, $program);

        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'path' => $this->pathLabel($participant),
            'weeks' => collect(self::SCORED_WEEKS)
                ->map(function (int $weekNumber) use ($summary): array {
                    $week = $summary['weeks'][$weekNumber];

                    return [
                        'average' => $week['average'],
                        'band' => $week['average'] !== null ? $this->scoring->band($week['average']) : null,
                        'completion' => $this->completion($week['completed'], $week['total']),
                    ];
                })
                ->all(),
            'average' => (string) ($summary['average'] ?? '—'),
            'completion' => $this->completion($summary['completed'], $summary['total']),
            'coach_names' => $participant->assignedCoaches->pluck('name')->implode(', ') ?: 'Unassigned',
            'scoring_url' => route('coach.scoring.participant', $participant),
        ];
    }

    /**
     * The QA path label of the scoring pages: Automation QA, otherwise Manual QA.
     */
    private function pathLabel(User $participant): string
    {
        return $participant->training_path === TrainingPath::Automation ? 'Automation QA' : 'Manual QA';
    }

    /**
     * "completed / total (percent%)".
     */
    private function completion(int $completed, int $total): string
    {
        return "{$completed} / {$total} ({$this->completionPercent($completed, $total)}%)";
    }

    private function completionPercent(int $completed, int $total): int
    {
        return $total ? (int) round($completed / $total * 100) : 0;
    }

    /**
     * The submitted versions, those with a published score and the share scored. Scoring completion is separate
     * from activity completion.
     *
     * @param  Collection<int, SubmissionVersion>  $versions
     * @return list<array{label: string, value: string}>
     */
    private function scoringCompletionStats(Collection $versions): array
    {
        $completed = $versions->count();
        $scored = $versions->filter(fn (SubmissionVersion $version): bool => $version->activityScore?->isPublished() === true)->count();

        return [
            ['label' => 'COMPLETED ACTIVITIES', 'value' => (string) $completed],
            ['label' => 'SCORED ACTIVITIES', 'value' => (string) $scored],
            ['label' => 'SCORING COMPLETION', 'value' => $this->completionPercent($scored, $completed).'%'],
        ];
    }

    /**
     * The scored weeks as select options, marking the week that matches the current filter value.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function weekOptions(int|string|null $selectedWeek): array
    {
        return collect(self::SCORED_WEEKS)
            ->map(fn (int $weekNumber): array => [
                'value' => (string) $weekNumber,
                'label' => "Week {$weekNumber}",
                'is_selected' => $selectedWeek !== null && (int) $selectedWeek === $weekNumber,
            ])
            ->all();
    }

    /**
     * Select options from value => label pairs, marking the option that matches the current filter value.
     *
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function options(array $labels, ?string $selectedValue): array
    {
        return collect($labels)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
                'is_selected' => $selectedValue === $value,
            ])
            ->values()
            ->all();
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
