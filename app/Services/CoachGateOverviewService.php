<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\ProgramParticipant;
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

    public function __construct(
        private readonly GateReadinessService $readiness,
    ) {}

    /**
     * Build the coach Gate board: one row per curriculum membership the viewer may see (every one for an admin,
     * otherwise those where the viewer is the participant's current mentor) and active Gate of one of the curriculum's
     * active weeks, each from the participant's work, sorted by status, with summary counts. The curricula are loaded
     * with their schedule bounds, so their dates need no query per row. `criteria_met` and `criteria_total` count only
     * the items on the checklist, so a tick on a since-retired item no longer counts.
     *
     * @param  array{week?: int|string|null, search?: string|null, status?: GateEvaluationStatus|null, program?: int|string|null}  $filters
     * @return array{rows: Collection<int, array{gate: TrainingGate, program: Program, participant: User, evaluation: ?GateEvaluation, readiness: array<string, string>, status: GateEvaluationStatus, criteria_met: int, criteria_total: int, weekly_video: array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording}}>, summary: array{total: int, ready: int, awaiting: int, passed: int, not_ready: int}}
     */
    public function board(User $viewer, array $filters): array
    {
        $gates = TrainingGate::query()->where('is_active', true)->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('week', fn (Builder $w): Builder => $w->where('week_number', $v)))->with(['week', 'criteria'])->get();
        $memberships = ProgramParticipant::query()
            ->whereHas('participant', fn (Builder $q): Builder => $q->where('role', UserRole::Participant)->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $s): Builder => $s->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%"))))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->when($filters['program'] ?? null, fn (Builder $query, int|string $programId): Builder => $query->where('program_id', $programId))
            ->with([
                'program' => fn (Relation $programs): Relation => $programs->select(['id', 'name'])->withScheduleBounds(),
                'participant',
            ])
            ->orderBy('id')
            ->get();
        $readinessByMembership = $this->readiness->readinessForMemberships($memberships, $gates);
        $weeklyVideosByMembership = $this->readiness->weeklyVideosForMemberships($memberships, $gates);
        $evaluations = $memberships->isEmpty() || $gates->isEmpty() ? collect() : GateEvaluation::query()
            ->whereIn('training_gate_id', $gates->modelKeys())
            ->whereIn('user_id', $memberships->pluck('user_id')->unique()->values())
            ->with('criterionEvaluations')
            ->get()
            ->keyBy(fn (GateEvaluation $evaluation): string => "{$evaluation->training_gate_id}:{$evaluation->user_id}");
        $rows = collect();
        foreach ($gates as $gate) {
            $onChecklistCriterionIds = $gate->criteria->filter(fn (GateCriterion $criterion): bool => $criterion->isOnChecklist())->modelKeys();
            foreach ($memberships as $key => $membership) {
                if (! $membership->program->includesWeek($gate->week)) {
                    continue;
                }
                $evaluation = $evaluations->get("{$gate->id}:{$membership->user_id}");
                $readinessReasons = $readinessByMembership->get($key)->get($gate->id);
                $status = $evaluation?->status ?? GateEvaluationStatus::fromReadiness($readinessReasons);
                if (($filters['status'] ?? null) && $status !== $filters['status']) {
                    continue;
                }
                $rows->push([
                    'gate' => $gate,
                    'program' => $membership->program,
                    'participant' => $membership->participant,
                    'evaluation' => $evaluation,
                    'readiness' => $readinessReasons,
                    'status' => $status,
                    'criteria_met' => $evaluation?->criterionEvaluations->where('status', GateCriterionStatus::Met)->whereIn('gate_criterion_id', $onChecklistCriterionIds)->count() ?? 0,
                    'criteria_total' => count($onChecklistCriterionIds),
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
     * Build a participant's Gate rows in one curriculum: the Gates of the curriculum's active weeks, each with the
     * participant's evaluation (null when not reviewed yet) and readiness reasons.
     *
     * @return Collection<int, array{gate: TrainingGate, evaluation: ?GateEvaluation, readiness: array<string, string>}>
     */
    public function participantProgramOverview(Program $program, User $participant): Collection
    {
        $gates = TrainingGate::query()
            ->whereIn('training_week_id', $program->activeWeeks()->reorder()->select('id'))
            ->with(['week', 'criteria'])
            ->get();
        $readiness = $this->readiness->readinessForGates($participant, $gates);
        $evaluations = GateEvaluation::query()
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
