<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ScoringService
{
    public const DIMENSIONS = [
        'D1' => ['label' => 'Deliverable quality', 'max' => 30],
        'D2' => ['label' => 'Validation & verification', 'max' => 25],
        'D3' => ['label' => 'AI-augmented workflow', 'max' => 20],
        'D4' => ['label' => 'Judgment & iteration', 'max' => 15],
        'D5' => ['label' => 'Evidence & reasoning', 'max' => 10],
    ];

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
     * @return array{rows: Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>, completed: int, total: int, scored: int, average: ?int}
     */
    public function weekly(User $participant, TrainingWeek $week): array
    {
        return $this->weeklySummaries(collect([$participant]), collect([$week]))[$participant->id][$week->id];
    }

    /**
     * @return array{weeks: Collection<int, array{rows: Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>, completed: int, total: int, scored: int, average: ?int}>, completed: int, total: int, scored: int, average: ?int}
     */
    public function summary(User $participant, Program $program): array
    {
        return $this->summaries(collect([$participant]), $program)[$participant->id];
    }

    /**
     * The scored weeks of the program: every active week, in position order.
     *
     * @return Collection<int, TrainingWeek>
     */
    public function scoredWeeks(Program $program): Collection
    {
        return $program->weeks()->where('is_active', true)->orderBy('position')->get();
    }

    /**
     * Summarize every active week of the program for each participant with a fixed number of queries.
     *
     * @param  Collection<int, User>  $participants
     * @return Collection<int, array{weeks: Collection<int, array{rows: Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>, completed: int, total: int, scored: int, average: ?int}>, completed: int, total: int, scored: int, average: ?int}> keyed by participant id
     */
    public function summaries(Collection $participants, Program $program): Collection
    {
        $weeks = $this->scoredWeeks($program);
        $weeklySummaries = $this->weeklySummaries($participants, $weeks);

        return $participants->mapWithKeys(function (User $participant) use ($weeks, $weeklySummaries): array {
            $participantWeeks = $weeks->mapWithKeys(fn (TrainingWeek $week): array => [$week->week_number => $weeklySummaries[$participant->id][$week->id]]);
            $rows = $participantWeeks->flatMap(fn (array $week): Collection => $week['rows']);

            return [$participant->id => ['weeks' => $participantWeeks, ...$this->totals($rows)]];
        });
    }

    /**
     * Score every active activity of the given weeks for each participant with a fixed number of queries.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, Collection<int, array{rows: Collection<int, array{activity: Activity, submission: ?ActivitySubmission, version: ?SubmissionVersion, score: ?ActivityScore}>, completed: int, total: int, scored: int, average: ?int}>> keyed by participant id, then week id
     */
    private function weeklySummaries(Collection $participants, Collection $weeks): Collection
    {
        $activities = Activity::query()->whereIn('training_week_id', $weeks->pluck('id'))->where('is_active', true)->orderBy('position')->get();
        $activitiesByWeek = $activities->groupBy('training_week_id');
        $attemptsByParticipantAndActivity = ActivitySubmission::query()
            ->whereIn('activity_id', $activities->modelKeys())
            ->whereIn('user_id', $participants->pluck('id'))
            ->with('versions.publishedActivityScore.dimensions')
            ->latest('attempt_number')
            ->get()
            ->groupBy(['user_id', 'activity_id']);

        return $participants->mapWithKeys(fn (User $participant): array => [
            $participant->id => $weeks->mapWithKeys(fn (TrainingWeek $week): array => [
                $week->id => $this->weekSummary(($activitiesByWeek[$week->id] ?? collect())->map(
                    fn (Activity $activity): array => $this->scoreRow($activity, $attemptsByParticipantAndActivity[$participant->id][$activity->id] ?? collect()),
                )),
            ]),
        ]);
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
