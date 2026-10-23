<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivityScoreSuggestion;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class ScoringPageService
{
    /** The AI scoring status under which suggestions can be generated and feedback improved. */
    private const AI_CONFIGURED = 'Configured';

    private const PARTICIPANTS_PER_PAGE = 25;

    private const SCORE_STATUS_OPTIONS = ['scored' => 'Scored', 'unscored' => 'Unscored'];

    private const BAND_OPTIONS = ['Exemplary' => 'Exemplary', 'Proficient' => 'Proficient', 'Developing' => 'Developing', 'Beginning' => 'Beginning', 'Minimal' => 'Minimal'];

    /** The band a submitted version without a score is counted under in the scoring reports' band distribution. */
    private const NOT_SCORED_BAND = 'Not Scored';

    /**
     * The lowest score of each performance band as a percentage of the maximum, highest band first (the 0–100 bands
     * of ScoringService::band()); a dimension's band ranges on the scoring criteria page scale them to its maximum.
     */
    private const BAND_MINIMUM_PERCENTAGES = ['Exemplary' => 90, 'Proficient' => 80, 'Developing' => 60, 'Beginning' => 35, 'Minimal' => 1];

    public function __construct(
        private readonly ScoringService $scoring,
        private readonly SubmissionAnswerPresenter $answers,
        private readonly AiProviderConfigurationService $configuration,
        private readonly ScoringReportService $reports,
    ) {}

    /**
     * Everything the coach scoring page of a submission version renders: the header (with the participant's GitHub
     * repository for the submission's session, null when they have none), the immutable submission (answers,
     * prompt / output history, evidence), the AI suggestions, the scoring fields with their values
     * (a suggestion draft kept from the page, otherwise the saved score, otherwise the suggestion when nothing is saved
     * yet), the live score context and the second-reader review. The scoring fields are the activity's scoring
     * dimensions; an activity without dimensions cannot be scored. The single main action is Save / Publish Score.
     *
     * @param  array<string, mixed>|null  $draft  The scoring form values flashed when a suggestion was generated.
     * @return array{version: SubmissionVersion, activityTitle: string, participantName: string, repositoryUrl: ?string, weekNumber: int, submissionStatus: string, submittedAt: string, answers: list<array{label: string, text: mixed, link: ?string}>, evidenceRows: list<array{title: string, detail: ?string}>, suggestion: ?ActivityScoreSuggestion, suggestionRows: list<array{heading: string, feedback: mixed, evidence_basis: mixed}>, evidenceProcessing: list<string>, suggestionData: ?array{dimensions: mixed, overall_feedback: ?string}, aiStatus: string, isAiConfigured: bool, canManage: bool, canScore: bool, rubricSummary: ?string, scoreMaximum: int, scoreFields: list<array{key: string, label: string, max: int, evidence_id: string, points_id: string, points: mixed, evidence_reasoning: mixed}>, overallFeedback: mixed, previousAverage: ?int, score: ?ActivityScore, canManageSecondReader: bool, readers: Collection<int, User>}
     */
    public function editData(SubmissionVersion $version, User $viewer, ?array $draft): array
    {
        $version->loadMissing(['submission.participant', 'submission.trainingSession', 'submission.activity.week', 'evidence', 'activityScore.dimensions', 'scoreSuggestion', 'interactions']);
        $submission = $version->submission;
        $score = $version->activityScore;
        $suggestion = $version->scoreSuggestion;
        $dimensions = $submission->activity->scoringDimensions();
        $canManage = $viewer->mentorsParticipantInSession($submission->participant, $submission->trainingSession);
        $canManageSecondReader = $canManage && (bool) $score?->second_reader_required;
        $aiStatus = $this->configuration->adminValues()['status'];

        return [
            'version' => $version,
            'activityTitle' => $submission->activity->title,
            'participantName' => $submission->participant->name,
            'repositoryUrl' => TrainingSessionParticipant::query()->forParticipantInSession($submission->training_session_id, $submission->user_id)->value('repository_url'),
            'weekNumber' => $submission->activity->week->week_number,
            'submissionStatus' => $submission->status->value,
            'submittedAt' => $version->submitted_at?->format('M j, Y g:i A') ?? '—',
            'answers' => $this->answerRows($version),
            'evidenceRows' => $this->evidenceRows($version->evidence),
            'suggestion' => $suggestion,
            'suggestionRows' => $this->suggestionRows($dimensions, $suggestion),
            'evidenceProcessing' => $this->evidenceProcessing($suggestion),
            'suggestionData' => $suggestion ? ['dimensions' => $suggestion->dimensions, 'overall_feedback' => $suggestion->overall_feedback] : null,
            'aiStatus' => $aiStatus,
            'isAiConfigured' => $aiStatus === self::AI_CONFIGURED,
            'canManage' => $canManage,
            'canScore' => $canManage && $dimensions !== [],
            'rubricSummary' => $this->rubricSummary($dimensions),
            'scoreMaximum' => $submission->activity->scoringMaximum(),
            'scoreFields' => $this->scoreFields($dimensions, $score, $suggestion, $draft),
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
     * Everything the scoring overview renders: whether a program is active (the page shows a notice when none is), the
     * filter values and options and the matching participants with the score and completion of every active week of
     * the program and overall. The page has no single main action, so every button is secondary.
     *
     * @param  array{search?: ?string, week?: int|string|null, activity?: int|string|null, score_status?: ?string, band?: ?string, assigned_to_me?: bool|int|string|null}  $filters
     * @return array{hasActiveProgram: bool, criteriaUrl: string, reportsUrl: string, search: string, weekOptions: list<array{value: string, label: string, is_selected: bool}>, scoreStatusOptions: list<array{value: string, label: string, is_selected: bool}>, bandOptions: list<array{value: string, label: string, is_selected: bool}>, isAssignedToMe: bool, weekNumbers: list<int>, participants: LengthAwarePaginator<int, array{name: string, email: string, weeks: list<array{average: ?int, band: ?string, completion: string}>, average: string, completion: string, coach_names: string, scoring_url: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        $program = $this->activeProgram();
        $participants = $this->filteredParticipants($viewer, $filters);
        $summaries = $this->scoring->summaries($participants->getCollection(), $program);

        return [
            'hasActiveProgram' => $program !== null,
            'criteriaUrl' => route('coach.scoring.criteria'),
            'reportsUrl' => route('coach.scoring.reports'),
            'search' => (string) ($filters['search'] ?? ''),
            'weekOptions' => $this->weekOptions($filters['week'] ?? null),
            'scoreStatusOptions' => $this->options(self::SCORE_STATUS_OPTIONS, $filters['score_status'] ?? null),
            'bandOptions' => $this->options(self::BAND_OPTIONS, $filters['band'] ?? null),
            'isAssignedToMe' => (bool) ($filters['assigned_to_me'] ?? false),
            'weekNumbers' => $this->scoring->scoredWeeks($program)->pluck('week_number')->all(),
            'participants' => $participants->through(fn (User $participant): array => $this->overviewRow($participant, $summaries[$participant->id])),
        ];
    }

    /**
     * Everything the participant scoring page renders: whether a program is active (the page shows a notice when none
     * is), the overall score and completion, and per scored week its average, completion and the activity rows with
     * the link to score (or view the score of) each submitted version.
     *
     * @return array{hasActiveProgram: bool, participantName: string, participantEmail: string, stats: list<array{label: string, value: string, note: ?string}>, weeks: list<array{number: int, heading: string, detail: string, rows: list<array{activity: string, score: string, band: string, status: string, action: ?array{label: string, url: string}}>}>}
     */
    public function participantData(User $participant): array
    {
        $program = $this->activeProgram();
        $summary = $this->scoring->summary($participant, $program);

        return [
            'hasActiveProgram' => $program !== null,
            'participantName' => $participant->name,
            'participantEmail' => $participant->email,
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
                        'status' => $row['submission']?->status->value ?? 'Not submitted',
                        'action' => $row['version'] && ! $row['version']->isDraft()
                            ? ['label' => $row['score'] ? 'View score' : 'Score activity', 'url' => route('coach.scoring.edit', $row['version'])]
                            : null,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The scoring criteria page: the active program's name and, per active activity of its active weeks (week, then
     * activity order), each scoring dimension with its maximum and the points range of every band.
     *
     * @return array{programName: ?string, activities: list<array{id: int, heading: string, dimensions: list<array{key: string, label: string, max: int, ranges: list<array{band: string, range: string}>}>}>}
     */
    public function criteriaData(): array
    {
        $weeks = TrainingWeek::query()
            ->inActiveProgram()
            ->with(['program', 'activities' => fn (Relation $query): Relation => $query->where('is_active', true)->orderBy('position')])
            ->orderBy('position')
            ->get();

        return [
            'programName' => $weeks->first()?->program->name,
            'activities' => $weeks
                ->flatMap(fn (TrainingWeek $week): array => $week->activities
                    ->map(fn (Activity $activity): array => [
                        'id' => $activity->id,
                        'heading' => "Week {$week->week_number} · {$activity->title}",
                        'dimensions' => collect($activity->scoringDimensions())
                            ->map(fn (array $dimension): array => [...$dimension, 'ranges' => $this->bandRanges($dimension['max'])])
                            ->all(),
                    ])
                    ->all())
                ->values()
                ->all(),
        ];
    }

    /**
     * Everything the scoring reports page renders: the two CSV export links (keeping the current query), the filter
     * options, the scoring completion counts of the submitted versions matching the filters and their score-band
     * distribution. The page has no single main action, so every button is secondary.
     *
     * @param  array{participant?: int|string|null, week?: int|string|null, activity?: int|string|null, assigned_coach?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{scoresExportUrl: string, participantSummaryExportUrl: string, weekOptions: list<array{value: string, label: string, is_selected: bool}>, scoreStatusOptions: list<array{value: string, label: string, is_selected: bool}>, bandOptions: list<array{value: string, label: string, is_selected: bool}>, stats: list<array{label: string, value: string}>, bandRows: list<array{band: string, count: int}>}
     */
    public function reportsData(array $filters, array $query): array
    {
        $versions = $this->reports->versions($filters)->get();
        $bandCounts = $versions->countBy(fn (SubmissionVersion $version): string => $version->publishedActivityScore?->performance_band ?? self::NOT_SCORED_BAND);

        return [
            'scoresExportUrl' => route('coach.reports.scores', $query),
            'participantSummaryExportUrl' => route('coach.reports.participant-summary', $query),
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
     * @param  array{participant?: int|string|null, week?: int|string|null, activity?: int|string|null, assigned_coach?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{stats: list<array{label: string, value: string}>}
     */
    public function completionData(array $filters): array
    {
        return ['stats' => $this->scoringCompletionStats($this->reports->versions($filters)->get())];
    }

    /**
     * The points range of every performance band for a dimension with the given maximum ("27–30", or "8" when the
     * range is one point, or "—" when the maximum is too small for the band).
     *
     * @return list<array{band: string, range: string}>
     */
    private function bandRanges(int $maximum): array
    {
        $ranges = [];
        $upper = $maximum;
        foreach (self::BAND_MINIMUM_PERCENTAGES as $band => $percentage) {
            $lower = (int) ceil($maximum * $percentage / 100);
            $ranges[] = ['band' => $band, 'range' => match (true) {
                $lower > $upper => '—',
                $lower === $upper => (string) $lower,
                default => "{$lower}–{$upper}",
            }];
            $upper = min($upper, $lower - 1);
        }

        return $ranges;
    }

    /**
     * "D1 30 · D2 25 · … · Total 100" for the activity's scoring dimensions, or null without dimensions.
     *
     * @param  list<array{key: string, label: string, max: int}>  $dimensions
     */
    private function rubricSummary(array $dimensions): ?string
    {
        if ($dimensions === []) {
            return null;
        }

        return collect($dimensions)
            ->map(fn (array $dimension): string => "{$dimension['key']} {$dimension['max']}")
            ->push('Total '.array_sum(array_column($dimensions, 'max')))
            ->implode(' · ');
    }

    private function activeProgram(): ?Program
    {
        return Program::query()->where('is_active', true)->first();
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
                'currentMentors',
                fn (Builder $coachQuery): Builder => $coachQuery->whereKey($viewer->id),
            ))
            ->when(($filters['score_status'] ?? null) === 'scored', fn (Builder $query): Builder => $query->whereHas('activitySubmissions.versions.publishedActivityScore'))
            ->when(($filters['score_status'] ?? null) === 'unscored', fn (Builder $query): Builder => $query->whereDoesntHave('activitySubmissions.versions.publishedActivityScore'))
            ->when($filters['band'] ?? null, fn (Builder $query, string $band): Builder => $query->whereHas(
                'activitySubmissions.versions.publishedActivityScore',
                fn (Builder $scoreQuery): Builder => $scoreQuery->where('performance_band', $band),
            ))
            ->with('currentMentors')
            ->paginate(self::PARTICIPANTS_PER_PAGE)
            ->withQueryString();
    }

    /**
     * One overview row: the participant, each scored week's average with its band (null while unscored) and
     * completion, the overall average and completion, the current mentors and the link to the participant's scoring.
     *
     * @param  array{weeks: \Illuminate\Support\Collection<int, array{completed: int, total: int, average: ?int}>, completed: int, total: int, average: ?int}  $summary  the participant's ScoringService summary
     * @return array{name: string, email: string, weeks: list<array{average: ?int, band: ?string, completion: string}>, average: string, completion: string, coach_names: string, scoring_url: string}
     */
    private function overviewRow(User $participant, array $summary): array
    {
        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'weeks' => $summary['weeks']
                ->map(fn (array $week): array => [
                    'average' => $week['average'],
                    'band' => $week['average'] !== null ? $this->scoring->band($week['average']) : null,
                    'completion' => $this->completion($week['completed'], $week['total']),
                ])
                ->values()
                ->all(),
            'average' => (string) ($summary['average'] ?? '—'),
            'completion' => $this->completion($summary['completed'], $summary['total']),
            'coach_names' => $participant->currentMentors->pluck('name')->unique()->implode(', ') ?: 'Unassigned',
            'scoring_url' => route('coach.scoring.participant', $participant),
        ];
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
        $scored = $versions->filter(fn (SubmissionVersion $version): bool => $version->publishedActivityScore !== null)->count();

        return [
            ['label' => 'COMPLETED ACTIVITIES', 'value' => (string) $completed],
            ['label' => 'SCORED ACTIVITIES', 'value' => (string) $scored],
            ['label' => 'SCORING COMPLETION', 'value' => $this->completionPercent($scored, $completed).'%'],
        ];
    }

    /**
     * The weeks of the active program as select options, marking the week that matches the current filter value.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function weekOptions(int|string|null $selectedWeek): array
    {
        return TrainingWeek::query()
            ->inActiveProgram()
            ->orderBy('week_number')
            ->pluck('week_number')
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
     * The submitted answers with the text to show (a scalar value as is, anything else as pretty-printed JSON) and the
     * link to open for a GitHub link field.
     *
     * @return list<array{label: string, text: mixed, link: ?string}>
     */
    private function answerRows(SubmissionVersion $version): array
    {
        $links = $this->answers->fieldLinks($version);

        return collect($this->answers->fields($version))
            ->map(fn (array $field): array => [
                'label' => $field['label'],
                'text' => is_scalar($field['value']) ? $field['value'] : json_encode($field['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'link' => $links[$field['key']] ?? null,
            ])
            ->values()
            ->all();
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
     * The AI-suggested score and feedback for each of the activity's dimensions that has a suggestion, in rubric order.
     *
     * @param  list<array{key: string, label: string, max: int}>  $dimensions
     * @return list<array{heading: string, feedback: mixed, evidence_basis: mixed}>
     */
    private function suggestionRows(array $dimensions, ?ActivityScoreSuggestion $suggestion): array
    {
        if (! $suggestion) {
            return [];
        }

        return collect($dimensions)
            ->filter(fn (array $dimension): bool => (bool) ($suggestion->dimensions[$dimension['key']] ?? null))
            ->map(function (array $dimension) use ($suggestion): array {
                $suggested = $suggestion->dimensions[$dimension['key']];

                return [
                    'heading' => "{$dimension['key']} · {$dimension['label']} — Suggested {$suggested['suggested_score']}/{$dimension['max']}",
                    'feedback' => $suggested['feedback'],
                    'evidence_basis' => empty($suggested['evidence_basis']) ? null : $suggested['evidence_basis'],
                ];
            })
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
                $line = ($status['filename'] ?? 'Evidence').' → '.str_replace('_', ' ', $status['status'] ?? 'unknown');

                return empty($status['strategy']) ? $line : $line.' ('.str_replace('_', ' ', $status['strategy']).')';
            })
            ->values()
            ->all();
    }

    /**
     * The scoring fields of each of the activity's dimensions with their values: the draft kept while generating a
     * suggestion, otherwise the saved score, otherwise (only while nothing is saved) the AI suggestion.
     *
     * @param  list<array{key: string, label: string, max: int}>  $dimensions
     * @param  array<string, mixed>|null  $draft
     * @return list<array{key: string, label: string, max: int, evidence_id: string, points_id: string, points: mixed, evidence_reasoning: mixed}>
     */
    private function scoreFields(array $dimensions, ?ActivityScore $score, ?ActivityScoreSuggestion $suggestion, ?array $draft): array
    {
        $draftDimensions = is_array($draft['dimensions'] ?? null) ? $draft['dimensions'] : [];
        $suggestedDimensions = $suggestion?->dimensions ?? [];

        return collect($dimensions)->map(function (array $dimension) use ($score, $draftDimensions, $suggestedDimensions): array {
            $key = $dimension['key'];
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
