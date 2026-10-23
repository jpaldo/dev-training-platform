<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(
        private readonly GateReadinessService $readiness,
    ) {}

    /**
     * Build the coach Gate board: one row per session membership and active Gate of one of the session's weeks, each
     * from the participant's work in that session, sorted by status, with summary counts.
     *
     * @param  array{week?: int|string|null, search?: string|null, status?: GateEvaluationStatus|null}  $filters
     * @return array{rows: Collection<int, array{gate: TrainingGate, trainingSession: TrainingSession, participant: User, evaluation: ?GateEvaluation, readiness: array<string, string>, status: GateEvaluationStatus, criteria_met: int, criteria_total: int, weekly_video: array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording}}>, summary: array{total: int, ready: int, awaiting: int, passed: int, not_ready: int}}
     */
    public function board(array $filters): array
    {
        $gates = TrainingGate::query()->where('is_active', true)->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('week', fn (Builder $w): Builder => $w->where('week_number', $v)))->with(['week', 'criteria'])->get();
        $memberships = TrainingSessionParticipant::query()
            ->whereHas('participant', fn (Builder $q): Builder => $q->where('role', UserRole::Participant)->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $s): Builder => $s->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%"))))
            ->with(['trainingSession', 'participant'])
            ->orderBy('id')
            ->get();
        $readinessByMembership = $this->readiness->readinessForMemberships($memberships, $gates);
        $weeklyVideosByMembership = $this->readiness->weeklyVideosForMemberships($memberships, $gates);
        $evaluations = $memberships->isEmpty() || $gates->isEmpty() ? collect() : GateEvaluation::query()
            ->whereIn('training_gate_id', $gates->modelKeys())
            ->whereIn('training_session_id', $memberships->pluck('training_session_id')->unique()->values())
            ->whereIn('user_id', $memberships->pluck('user_id')->unique()->values())
            ->with('criterionEvaluations')
            ->get()
            ->keyBy(fn (GateEvaluation $evaluation): string => "{$evaluation->training_session_id}:{$evaluation->training_gate_id}:{$evaluation->user_id}");
        $rows = collect();
        foreach ($gates as $gate) {
            foreach ($memberships as $key => $membership) {
                if (! $membership->trainingSession->includesWeek($gate->week)) {
                    continue;
                }
                $evaluation = $evaluations->get("{$membership->training_session_id}:{$gate->id}:{$membership->user_id}");
                $readinessReasons = $readinessByMembership->get($key)->get($gate->id);
                $status = $evaluation?->status ?? GateEvaluationStatus::fromReadiness($readinessReasons);
                if (($filters['status'] ?? null) && $status !== $filters['status']) {
                    continue;
                }
                $rows->push([
                    'gate' => $gate,
                    'trainingSession' => $membership->trainingSession,
                    'participant' => $membership->participant,
                    'evaluation' => $evaluation,
                    'readiness' => $readinessReasons,
                    'status' => $status,
                    'criteria_met' => $evaluation?->criterionEvaluations->where('status', GateCriterionStatus::Met)->count() ?? 0,
                    'criteria_total' => $gate->criteria->where('is_required', true)->count(),
                    'weekly_video' => $weeklyVideosByMembership->get($key)->get($gate->id),
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
     * Build a participant's Gate rows in one session: the Gates of the session's weeks, each with the participant's
     * evaluation (null when not reviewed yet) and readiness reasons in that session.
     *
     * @return Collection<int, array{gate: TrainingGate, evaluation: ?GateEvaluation, readiness: array<string, string>}>
     */
    public function participantSessionOverview(TrainingSession $trainingSession, User $participant): Collection
    {
        $gates = TrainingGate::query()
            ->whereIn('training_week_id', $trainingSession->weeks()->reorder()->select('id'))
            ->with(['week', 'criteria'])
            ->get();
        $readiness = $this->readiness->readinessForGates($trainingSession, $participant, $gates);
        $evaluations = GateEvaluation::query()
            ->where('training_session_id', $trainingSession->id)
            ->where('user_id', $participant->id)
            ->whereIn('training_gate_id', $gates->modelKeys())
            ->get()
            ->keyBy('training_gate_id');

        return $gates->map(fn (TrainingGate $gate): array => [
            'gate' => $gate,
            'evaluation' => $evaluations->get($gate->id),
            'readiness' => $readiness->get($gate->id),
        ]);
    }
}
