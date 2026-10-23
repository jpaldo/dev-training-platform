<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CoachGateBoardPageService
{
    private const ROWS_PER_PAGE = 25;

    private const STAT_LABELS = [
        'total' => 'TOTAL GATES',
        'ready' => 'READY FOR REVIEW',
        'awaiting' => 'AWAITING REVIEW',
        'passed' => 'COMPLETED',
        'not_ready' => 'NOT READY',
    ];

    /** @var array<string, string> The status filter's option texts by status value, in display order (Failed stays for older reviews). */
    private const STATUS_OPTIONS = [
        GateEvaluationStatus::NotReady->value => 'Not Ready',
        GateEvaluationStatus::ReadyForReview->value => 'Ready for Review',
        GateEvaluationStatus::AwaitingReview->value => 'Awaiting Review',
        GateEvaluationStatus::Passed->value => 'Completed',
        GateEvaluationStatus::Failed->value => 'Failed',
        GateEvaluationStatus::NeedsRevision->value => 'Incomplete',
    ];

    public function __construct(
        private readonly CoachGateOverviewService $gateOverview,
    ) {}

    /**
     * Everything the coach Gate board renders: the summary counts with their labels, the filter values and options
     * (the weeks of the active program), and the Gate x participant rows of the requested page (25 per page, query string kept). The page has no single
     * main action, so every button is secondary.
     *
     * @param  array{week?: int|string|null, search?: string|null, status?: GateEvaluationStatus|null}  $filters
     * @return array{stats: list<array{label: string, value: int}>, search: string, weekOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, gateRows: LengthAwarePaginator<int, array{week_label: string, gate_title: string, participant_name: string, participant_email: string, participant_url: string, status_label: string, status_tone: string, criteria_met: int, criteria_total: int, video: array{url: ?string, label: string}, blocker_label: ?string, action_label: string, action_url: string}>}
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
            'weekOptions' => TrainingWeek::query()
                ->inActiveProgram()
                ->orderBy('week_number')
                ->pluck('week_number')
                ->map(fn (int $week): array => [
                    'value' => (string) $week,
                    'label' => "Week {$week}",
                    'is_selected' => (string) ($filters['week'] ?? '') === (string) $week,
                ])
                ->all(),
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
     * One table row: the Gate and its week, the participant, the status with its badge tone, the
     * required criteria met, the weekly video (a link when chosen, otherwise Missing, or Not required when the Gate has no weekly video criterion), the blocker count (null without blockers) and the link to review (or, once completed, view) the Gate.
     *
     * @param  array{gate: TrainingGate, participant: User, evaluation: ?GateEvaluation, readiness: array<string, string>, status: GateEvaluationStatus, criteria_met: int, criteria_total: int, weekly_video: array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording}}  $row
     * @return array{week_label: string, gate_title: string, participant_name: string, participant_email: string, participant_url: string, status_label: string, status_tone: string, criteria_met: int, criteria_total: int, video: array{url: ?string, label: string}, blocker_label: ?string, action_label: string, action_url: string}
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
            'status_label' => strtoupper($row['status']->label()),
            'status_tone' => match ($row['status']) {
                GateEvaluationStatus::Passed => 'success',
                GateEvaluationStatus::Failed => 'danger',
                GateEvaluationStatus::NeedsRevision => 'warning',
                GateEvaluationStatus::ReadyForReview, GateEvaluationStatus::AwaitingReview => 'accent',
                GateEvaluationStatus::NotReady => 'neutral',
            },
            'criteria_met' => $row['criteria_met'],
            'criteria_total' => $row['criteria_total'],
            'video' => [
                'url' => $row['weekly_video']['url'],
                'label' => match ($row['weekly_video']['status']) {
                    'not_required' => 'Not required',
                    'missing' => 'Missing',
                    'met' => '✓ Chosen',
                    default => 'Awaiting Review',
                },
            ],
            'blocker_label' => $blockerCount > 0 ? $blockerCount.' blocker'.($blockerCount === 1 ? '' : 's') : null,
            'action_label' => $row['status'] === GateEvaluationStatus::Passed ? 'View Gate' : 'Review Gate',
            'action_url' => route('training.coach.gates.show', [$gate->week, $participant, $gate]),
        ];
    }
}
