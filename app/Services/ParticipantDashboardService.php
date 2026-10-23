<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ParticipantDashboardService
{
    public function __construct(private readonly WeekProgressionService $progression, private readonly GateReadinessService $gateService) {}

    /**
     * Build the dashboard rows for every week of the program, with the participant's activity progress attached. An
     * available week shows its Weekly Video Recording only when it asks for one; the program's weeks are expected to
     * be loaded with TrainingWeek::withAsksForWeeklyVideo().
     *
     * @return Collection<int, array{week: TrainingWeek, passed: bool, available: bool, locked_reason: ?string, shows_weekly_video: bool, is_current: bool, attendance_required: bool}>
     */
    public function weeks(User $user, Program $program, ?AttendanceSession $activeAttendance): Collection
    {
        $programWeeks = $program->weeks;
        $weekStates = $this->progression->weekStates($user, $programWeeks);
        $videoRecordings = WeeklyVideoRecording::query()->where('user_id', $user->id)->whereIn('training_week_id', $programWeeks->modelKeys())->get()->keyBy('training_week_id');
        $this->attachActivityProgress($user, $programWeeks);

        $weeks = $programWeeks->sortBy('position')->values()->map(function (TrainingWeek $week) use ($weekStates, $videoRecordings): array {
            $passed = $weekStates[$week->id]['passed'];
            $available = $passed || $weekStates[$week->id]['accessible'];
            $week->weekly_video_recording = $videoRecordings->get($week->id);

            return [
                'week' => $week,
                'passed' => $passed,
                'available' => $available,
                'locked_reason' => $passed ? null : ($available ? null : 'Your mentor must mark the previous week completed before you can continue.'),
                'shows_weekly_video' => $available && $week->asksForWeeklyVideo(),
            ];
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
     * @return Collection<int, array{week: TrainingWeek, gate: TrainingGate, evaluation: ?GateEvaluation, readiness: array<string, string>, status_label: string}>
     */
    public function gates(User $user, Program $program): Collection
    {
        $weeks = $program->weeks()->where('is_active', true)->with(['gates' => fn (Relation $query): Relation => $query->where('is_active', true)->with(['criteria', 'evaluations' => fn (Relation $evaluation): Relation => $evaluation->where('user_id', $user->id)])])->get();
        $readinessByGate = $this->gateService->readinessForGates($user, $weeks->pluck('gates')->collapse());

        return $weeks->flatMap(function (TrainingWeek $week) use ($readinessByGate): array {
            return $week->gates->map(function (TrainingGate $gate) use ($week, $readinessByGate): array {
                $evaluation = $gate->evaluations->first();
                $readiness = $readinessByGate->get($gate->id);

                return [
                    ...compact('week', 'gate', 'evaluation', 'readiness'),
                    'status_label' => ($evaluation?->status ?? GateEvaluationStatus::fromReadiness($readiness))->label(),
                ];
            })->all();
        })->values();
    }

    /**
     * Attach the participant's latest submission with its status label, badge tone and next action, the attempts, the
     * published score and the activity prerequisites to every activity.
     *
     * @param  EloquentCollection<int, TrainingWeek>  $weeks  weeks with their activities loaded
     */
    private function attachActivityProgress(User $user, EloquentCollection $weeks): void
    {
        $attemptsByActivity = $user->activitySubmissions()
            ->whereIn('activity_id', $weeks->pluck('activities')->collapse()->pluck('id'))
            ->with('versions.publishedActivityScore')
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('activity_id');

        foreach ($weeks as $week) {
            foreach ($week->activities as $activity) {
                $attempts = $attemptsByActivity->get($activity->id, new EloquentCollection);
                $activity->participant_submission = $attempts->last();
                $activity->participant_attempts = $attempts->values();
                $activity->participant_status_label = $activity->participant_submission?->status->label() ?? 'Not Started';
                $activity->participant_status_tone = $activity->participant_submission?->status->tone() ?? 'neutral';
                $activity->participant_action = $this->activityAction($activity->participant_submission?->status);
                $activity->participant_score = $activity->participant_submission?->versions->sortByDesc('version_number')->first(fn (SubmissionVersion $version): bool => $version->publishedActivityScore !== null)?->publishedActivityScore;
                $activity->participant_score_max = $activity->scoringMaximum();
            }
        }
    }

    /**
     * The text of the activity's action button for the latest attempt's status.
     */
    private function activityAction(?SubmissionStatus $status): string
    {
        return match ($status) {
            SubmissionStatus::NeedsRevision => 'Revise',
            SubmissionStatus::Draft => 'Continue',
            SubmissionStatus::Failed => 'View attempts',
            SubmissionStatus::Submitted, SubmissionStatus::Resubmitted, SubmissionStatus::UnderReview, SubmissionStatus::Approved, SubmissionStatus::Completed => 'View',
            null => 'Start',
        };
    }
}
