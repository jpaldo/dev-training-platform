<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ScoringService
{
    /** Why an activity without scoring dimensions can neither be scored nor get an AI suggestion. */
    public const NO_DIMENSIONS_MESSAGE = 'This activity has no scoring dimensions, so it cannot be scored.';

    public function band(?int $score): string
    {
        return match (true) {
            $score === null || $score === 0 => 'Not submitted / Not evidenced',
            $score >= 90 => 'Exemplary',
            $score >= 80 => 'Proficient',
            $score >= 60 => 'Developing',
            $score >= 35 => 'Beginning',
            default => 'Minimal',
        };
    }

    public function requiresSecondReader(int $total, ?int $runningAverage = null): bool
    {
        return ($total >= 76 && $total <= 84) || ($runningAverage !== null && abs($total - $runningAverage) > 15);
    }

    /**
     * Summarize every week of each membership's training session (TrainingSession::includesWeek()), by week number,
     * from the participant's attempts in that session only, with a fixed number of queries. A participant in two
     * sessions gets one summary per session.
     *
     * @param  Collection<int, TrainingSessionParticipant>  $memberships  each with its trainingSession loaded
     * @return Collection<int, array{weeks: Collection<int, array{rows: Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>, completed: int, total: int, scored: int, average: ?int}>, completed: int, total: int, scored: int, average: ?int}> keyed by membership id
     */
    public function sessionSummaries(Collection $memberships): Collection
    {
        if ($memberships->isEmpty()) {
            return collect();
        }

        $trainingSessions = $memberships->pluck('trainingSession')->unique('id');
        $weeks = TrainingWeek::query()
            ->whereIn('program_id', $trainingSessions->pluck('program_id')->unique()->values())
            ->where('is_active', true)
            ->orderBy('week_number')
            ->get();
        $activitiesByWeek = Activity::query()->whereIn('training_week_id', $weeks->modelKeys())->where('is_active', true)->orderBy('position')->get()->groupBy('training_week_id');
        $attempts = ActivitySubmission::query()
            ->whereIn('training_session_id', $trainingSessions->pluck('id'))
            ->whereIn('user_id', $memberships->pluck('user_id')->unique()->values())
            ->whereIn('activity_id', $activitiesByWeek->flatten()->pluck('id'))
            ->with('versions.publishedActivityScore.dimensions')
            ->latest('attempt_number')
            ->get()
            ->groupBy(['training_session_id', 'user_id', 'activity_id']);

        return $memberships->mapWithKeys(function (TrainingSessionParticipant $membership) use ($weeks, $activitiesByWeek, $attempts): array {
            $trainingSession = $membership->trainingSession;
            $sessionWeeks = $weeks
                ->filter(fn (TrainingWeek $week): bool => $trainingSession->includesWeek($week))
                ->mapWithKeys(fn (TrainingWeek $week): array => [
                    $week->week_number => $this->weekSummary(($activitiesByWeek[$week->id] ?? collect())->map(
                        fn (Activity $activity): array => $this->scoreRow($activity, $attempts[$trainingSession->id][$membership->user_id][$activity->id] ?? collect()),
                    )),
                ]);
            $rows = $sessionWeeks->flatMap(fn (array $week): Collection => $week['rows']);

            return [$membership->id => ['weeks' => $sessionWeeks, ...$this->totals($rows)]];
        });
    }

    /**
     * @param  Collection<int, ActivitySubmission>  $attempts  the participant's submissions of the activity, latest attempt first
     * @return array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}
     */
    private function scoreRow(Activity $activity, Collection $attempts): array
    {
        $submission = $attempts->first();
        $version = $submission?->versions->sortByDesc('version_number')->first(fn (SubmissionVersion $v): bool => in_array($v->status, SubmissionStatus::handedIn(), true)) ?? $submission?->versions->sortByDesc('version_number')->first();

        return ['activity' => $activity, 'submission' => $submission, 'version' => $version, 'score' => $version?->publishedActivityScore];
    }

    /**
     * @param  Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>  $rows
     * @return array{rows: Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>, completed: int, total: int, scored: int, average: ?int}
     */
    private function weekSummary(Collection $rows): array
    {
        return ['rows' => $rows, ...$this->totals($rows)];
    }

    /**
     * Completion and published-score totals of a set of activity rows.
     *
     * @param  Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>  $rows
     * @return array{completed: int, total: int, scored: int, average: ?int}
     */
    private function totals(Collection $rows): array
    {
        $scored = $rows->filter(fn (array $row): bool => $row['score']?->total_score !== null);
        $completed = $rows->filter(fn (array $row): bool => $row['submission']?->isHandedIn() === true)->count();

        return ['completed' => $completed, 'total' => $rows->count(), 'scored' => $scored->count(), 'average' => $scored->isEmpty() ? null : (int) round($scored->avg(fn (array $row): int => $row['score']->total_score))];
    }

    /**
     * The participant's average published total score, leaving out the given submission version's score, or null
     * without a published score.
     */
    public function runningAverage(User $participant, ?int $exceptVersion = null): ?int
    {
        $scores = ActivityScore::query()->published()->whereHas('version.submission', fn (Builder $q): Builder => $q->where('user_id', $participant->id))->when($exceptVersion, fn (Builder $q): Builder => $q->where('submission_version_id', '!=', $exceptVersion))->pluck('total_score');

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }
}
