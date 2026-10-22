<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\AttendanceSession;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ParticipantDashboardService
{
    public function __construct(private readonly WeekProgressionService $progression, private readonly WeekTwoGateService $gateService) {}

    /**
     * Build the dashboard rows for every week of the program, with the participant's activity progress attached.
     *
     * @return Collection<int, array{week: TrainingWeek, passed: bool, available: bool, daily_log_count: int, daily_log_required: int, locked_reason: ?string, is_current: bool, attendance_required: bool}>
     */
    public function weeks(User $user, Program $program, ?AttendanceSession $activeAttendance): Collection
    {
        $programWeeks = $program->weeks;
        $weekStates = $this->progression->weekStates($user, $programWeeks);
        $dailyLogCounts = $this->gateService->dailyLogCountsByWeek($user, $programWeeks->modelKeys());
        $videoRecordings = WeeklyVideoRecording::query()->where('user_id', $user->id)->whereIn('training_week_id', $programWeeks->modelKeys())->get()->keyBy('training_week_id');
        $this->attachActivityProgress($user, $programWeeks);

        $weeks = $programWeeks->sortBy('position')->values()->map(function (TrainingWeek $week) use ($weekStates, $dailyLogCounts, $videoRecordings): array {
            $passed = $weekStates[$week->id]['passed'];
            $available = $passed || $weekStates[$week->id]['accessible'];
            $week->weekly_video_recording = $videoRecordings->get($week->id);

            return ['week' => $week, 'passed' => $passed, 'available' => $available, 'daily_log_count' => $dailyLogCounts->get($week->id, 0), 'daily_log_required' => 5, 'locked_reason' => $passed ? null : ($available ? null : 'Complete and pass the previous Gate before proceeding.')];
        });
        $currentWeek = $weeks->first(fn (array $item): bool => $item['available'] && ! $item['passed']);
        $currentWeekId = $currentWeek['week']->id ?? null;

        return $weeks->map(function (array $item) use ($currentWeekId, $activeAttendance): array {
            $item['is_current'] = $item['week']->id === $currentWeekId;
            $item['attendance_required'] = $item['is_current'] && ! $activeAttendance;

            return $item;
        });
    }

    /**
     * Build the Gate rows of the program's active weeks with the participant's evaluation, readiness and status label
     * (the saved evaluation status, or whether the Gate is ready for Coach review while it has none).
     *
     * @return Collection<int, array{week: TrainingWeek, gate: TrainingGate, evaluation: ?GateEvaluation, readiness: array<string, string>, criterionEvaluations: Collection<int, GateCriterionEvaluation>, status_label: string}>
     */
    public function gates(User $user, Program $program): Collection
    {
        $weeks = $program->weeks()->where('is_active', true)->with(['gates' => fn (Relation $query): Relation => $query->where('is_active', true)->with(['criteria', 'evaluations' => fn (Relation $evaluation): Relation => $evaluation->where('user_id', $user->id)->with('criterionEvaluations')])])->get();
        $readinessByGate = $this->gateService->readinessForGates($user, $weeks->pluck('gates')->collapse());

        return $weeks->flatMap(function (TrainingWeek $week) use ($readinessByGate): array {
            return $week->gates->map(function (TrainingGate $gate) use ($week, $readinessByGate): array {
                $evaluation = $gate->evaluations->first();
                $readiness = $readinessByGate->get($gate->id);
                $criterionEvaluations = $evaluation?->criterionEvaluations->keyBy('gate_criterion_id') ?? collect();

                return [
                    ...compact('week', 'gate', 'evaluation', 'readiness', 'criterionEvaluations'),
                    'status_label' => ($evaluation?->status ?? GateEvaluationStatus::fromReadiness($readiness))->label(),
                ];
            })->all();
        })->values();
    }

    /**
     * Attach the participant's latest submission, attempts, published score and activity prerequisites to every activity.
     *
     * @param  EloquentCollection<int, TrainingWeek>  $weeks  weeks with their activities loaded
     */
    private function attachActivityProgress(User $user, EloquentCollection $weeks): void
    {
        $attemptsByActivity = $user->activitySubmissions()
            ->whereIn('activity_id', $weeks->pluck('activities')->collapse()->pluck('id'))
            ->with('versions.activityScore')
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('activity_id');
        $scoreMax = array_sum(array_column(ScoringService::DIMENSIONS, 'max'));
        $hasWeekFourPartner = null;
        $hasValidationLabRecord = null;

        foreach ($weeks as $week) {
            foreach ($week->activities as $activity) {
                $attempts = $attemptsByActivity->get($activity->id, new EloquentCollection);
                $activity->participant_submission = $attempts->last();
                $activity->participant_attempts = $attempts->values();
                $activity->participant_score = $activity->participant_submission?->versions->sortByDesc('version_number')->first(fn (SubmissionVersion $version): bool => $version->activityScore?->isPublished() === true)?->activityScore;
                $activity->participant_score_max = $scoreMax;
                $activity->week_four_partner_assigned = ! $this->requiresWeekFourPartner($week, $activity) || ($hasWeekFourPartner ??= (bool) WeekFourInterviewPair::forParticipant($user->id));
                $activity->lab_prerequisite_met = ! $this->requiresValidationLabRecord($week, $activity)
                    || ($hasValidationLabRecord ??= $user->validationSubmissions()->whereIn('status', ValidationSubmissionStatus::recorded())->exists());
            }
        }
    }

    private function requiresWeekFourPartner(TrainingWeek $week, Activity $activity): bool
    {
        return $week->week_number === 4 && $activity->position === 2;
    }

    private function requiresValidationLabRecord(TrainingWeek $week, Activity $activity): bool
    {
        return $week->week_number === 2 && $activity->position === 1 && $activity->title === 'Validation Checklist';
    }
}
