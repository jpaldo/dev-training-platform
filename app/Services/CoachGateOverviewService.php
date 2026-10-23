<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class CoachGateOverviewService
{
    /** @var array<string, int> Gate board sort order by status value, most actionable status first. */
    private const STATUS_ORDER = [
        GateEvaluationStatus::ReadyForReview->value => 1,
        GateEvaluationStatus::AwaitingReview->value => 2,
        GateEvaluationStatus::NeedsRevision->value => 3,
        GateEvaluationStatus::NotReady->value => 4,
        GateEvaluationStatus::Failed->value => 5,
        GateEvaluationStatus::Passed->value => 6,
    ];

    public function __construct(private readonly GateReadinessService $readiness) {}

    /**
     * Build the coach Gate board: one row per active Gate and participant, sorted by status, with summary counts.
     *
     * @param  array{week?: int|string|null, path?: string|null, search?: string|null, status?: GateEvaluationStatus|null}  $filters
     * @return array{rows: Collection<int, array{gate: TrainingGate, participant: User, evaluation: ?GateEvaluation, readiness: array<string, string>, status: GateEvaluationStatus, criteria_met: int, criteria_total: int, weekly_video: array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording}}>, summary: array{total: int, ready: int, awaiting: int, passed: int, not_ready: int}}
     */
    public function board(array $filters): array
    {
        $gates = TrainingGate::query()->where('is_active', true)->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('week', fn (Builder $w): Builder => $w->where('week_number', $v)))->with(['week', 'criteria', 'evaluations' => fn (Relation $q): Relation => $q->with('criterionEvaluations')])->get();
        $participants = User::query()->where('role', UserRole::Participant)->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->where('training_path', $v))->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $s): Builder => $s->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))->get();
        $readinessByParticipant = $this->readiness->readinessForParticipants($participants, $gates);
        $weeklyVideosByParticipant = $this->readiness->weeklyVideosForParticipants($participants, $gates);
        $rows = collect();
        foreach ($gates as $gate) {
            foreach ($participants as $participant) {
                $evaluation = $gate->evaluations->firstWhere('user_id', $participant->id);
                $readinessReasons = $readinessByParticipant->get($participant->id)->get($gate->id);
                $status = $evaluation?->status ?? GateEvaluationStatus::fromReadiness($readinessReasons);
                if (($filters['status'] ?? null) && $status !== $filters['status']) {
                    continue;
                }
                $rows->push([
                    'gate' => $gate,
                    'participant' => $participant,
                    'evaluation' => $evaluation,
                    'readiness' => $readinessReasons,
                    'status' => $status,
                    'criteria_met' => $evaluation?->criterionEvaluations->where('status', GateCriterionStatus::Met)->count() ?? 0,
                    'criteria_total' => $gate->criteria->where('is_required', true)->count(),
                    'weekly_video' => $weeklyVideosByParticipant->get($participant->id)->get($gate->id),
                ]);
            }
        }
        $rows = $rows->sortBy(fn (array $row): int => self::STATUS_ORDER[$row['status']->value])->values();

        return [
            'rows' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'ready' => $rows->where('status', GateEvaluationStatus::ReadyForReview)->count(),
                'awaiting' => $rows->where('status', GateEvaluationStatus::AwaitingReview)->count(),
                'passed' => $rows->where('status', GateEvaluationStatus::Passed)->count(),
                'not_ready' => $rows->where('status', GateEvaluationStatus::NotReady)->count(),
            ],
        ];
    }

    /**
     * Build a participant's Gate rows: the Gates of every active week of the active program.
     *
     * @return array{gateRows: Collection<int, array{gate: TrainingGate, evaluation: ?GateEvaluation, readiness: ?array<string, string>}>}
     */
    public function participantOverview(User $participant): array
    {
        $gates = TrainingGate::query()->whereHas('week', fn (Builder $q): Builder => $q->inActiveProgram())->with(['week', 'criteria', 'evaluations' => fn (Relation $q): Relation => $q->where('user_id', $participant->id)])->get();
        $readinessByGate = $this->readiness->readinessForGates($participant, $gates);

        return [
            'gateRows' => $gates->map(fn (TrainingGate $gate): array => ['gate' => $gate, 'evaluation' => $gate->evaluations->first(), 'readiness' => $readinessByGate->get($gate->id)]),
        ];
    }
}
