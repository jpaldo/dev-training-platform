<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CoachGateBoardPageService
{
    private const ROWS_PER_PAGE = 25;

    private const REQUIRED_DAILY_LOGS_PER_WEEK = 5;

    private const STAT_LABELS = [
        'total' => 'TOTAL GATES',
        'ready' => 'READY FOR REVIEW',
        'awaiting' => 'AWAITING REVIEW',
        'passed' => 'PASSED',
        'not_ready' => 'NOT READY',
    ];

    private const WEEK_OPTIONS = [2, 3, 4];

    private const PATH_OPTIONS = ['manual' => 'Manual QA', 'automation' => 'Automation QA'];

    /** @var array<string, string> The status filter's option texts by status value, in display order. */
    private const STATUS_OPTIONS = [
        GateEvaluationStatus::NotReady->value => 'Not Ready',
        GateEvaluationStatus::ReadyForReview->value => 'Ready for Review',
        GateEvaluationStatus::AwaitingReview->value => 'Awaiting Review',
        GateEvaluationStatus::Passed->value => 'Passed',
        GateEvaluationStatus::Failed->value => 'Failed',
        GateEvaluationStatus::NeedsRevision->value => 'Needs Revision',
    ];

    public function __construct(private readonly CoachGateOverviewService $gateOverview) {}

    /**
     * Everything the coach Gate board renders: the summary counts with their labels, the filter values and options,
     * and the Gate x participant rows of the requested page (25 per page, query string kept). The page has no single
     * main action, so every button is secondary.
     *
     * @param  array{week?: int|string|null, path?: string|null, search?: string|null, status?: GateEvaluationStatus|null}  $filters
     * @return array{stats: list<array{label: string, value: int}>, search: string, weekOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, gateRows: LengthAwarePaginator<int, array{week_label: string, gate_title: string, participant_name: string, participant_email: string, participant_url: string, path: string, status_label: string, status_tone: string, daily_log_progress: string, daily_log_note: string, criteria_met: int, criteria_total: int, video: ?array{url: string, label: string}, blocker_label: ?string, action_label: string, action_url: string}>}
     */
    public function indexData(array $filters, int $page): array
    {
        ['rows' => $rows, 'summary' => $summary] = $this->gateOverview->board($filters);

        $gateRows = new LengthAwarePaginator(
            $rows->forPage($page, self::ROWS_PER_PAGE)->values(),
            $rows->count(),
            self::ROWS_PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return [
            'stats' => collect(self::STAT_LABELS)
                ->map(fn (string $label, string $key): array => ['label' => $label, 'value' => $summary[$key]])
                ->values()
                ->all(),
            'search' => (string) ($filters['search'] ?? ''),
            'weekOptions' => collect(self::WEEK_OPTIONS)
                ->map(fn (int $week): array => [
                    'value' => (string) $week,
                    'label' => "Week {$week}",
                    'is_selected' => (string) ($filters['week'] ?? '') === (string) $week,
                ])
                ->all(),
            'pathOptions' => $this->options(self::PATH_OPTIONS, $filters['path'] ?? null),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, ($filters['status'] ?? null)?->value),
            'gateRows' => $gateRows
                ->withQueryString()
                ->through(fn (array $row): array => $this->gateRow($row)),
        ];
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
     * One table row: the Gate and its week, the participant with their path, the status with its badge tone, the
     * week's daily log progress, the required criteria met, the weekly video link (null when missing), the blocker
     * count (null without blockers) and the link to review (or, once passed, view) the Gate.
     *
     * @param  array{gate: TrainingGate, participant: User, evaluation: ?GateEvaluation, readiness: array<string, string>, status: GateEvaluationStatus, daily_log_count: int, criteria_met: int, criteria_total: int, loom: array<string, mixed>}  $row
     * @return array{week_label: string, gate_title: string, participant_name: string, participant_email: string, participant_url: string, path: string, status_label: string, status_tone: string, daily_log_progress: string, daily_log_note: string, criteria_met: int, criteria_total: int, video: ?array{url: string, label: string}, blocker_label: ?string, action_label: string, action_url: string}
     */
    private function gateRow(array $row): array
    {
        $gate = $row['gate'];
        $participant = $row['participant'];
        $blockerCount = count($row['readiness']);

        return [
            'week_label' => "Week {$gate->week->week_number}",
            'gate_title' => $gate->title,
            'participant_name' => $participant->name,
            'participant_email' => $participant->email,
            'participant_url' => route('coach.participants.show', $participant),
            'path' => $participant->trainingPathLabel(),
            'status_label' => strtoupper($row['status']->label()),
            'status_tone' => match ($row['status']) {
                GateEvaluationStatus::Passed => 'success',
                GateEvaluationStatus::Failed => 'danger',
                GateEvaluationStatus::NeedsRevision => 'warning',
                GateEvaluationStatus::ReadyForReview, GateEvaluationStatus::AwaitingReview => 'accent',
                GateEvaluationStatus::NotReady => 'neutral',
            },
            'daily_log_progress' => $row['daily_log_count'].' / '.self::REQUIRED_DAILY_LOGS_PER_WEEK,
            'daily_log_note' => $row['daily_log_count'] < self::REQUIRED_DAILY_LOGS_PER_WEEK
                ? (self::REQUIRED_DAILY_LOGS_PER_WEEK - $row['daily_log_count']).' remaining'
                : 'Complete',
            'criteria_met' => $row['criteria_met'],
            'criteria_total' => $row['criteria_total'],
            'video' => $row['loom']['url']
                ? ['url' => $row['loom']['url'], 'label' => $row['loom']['status'] === 'met' ? '✓ Uploaded' : 'Awaiting Review']
                : null,
            'blocker_label' => $blockerCount > 0 ? $blockerCount.' blocker'.($blockerCount === 1 ? '' : 's') : null,
            'action_label' => $row['status'] === GateEvaluationStatus::Passed ? 'View Gate' : 'Review Gate',
            'action_url' => route('training.coach.gates.show', [$gate->week, $participant, $gate]),
        ];
    }
}
