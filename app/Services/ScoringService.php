<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    public function weekly(User $participant, $week): array
    {
        $activities = $week->activities()->where('is_active', true)->with(['submissions' => fn (Relation $q): Relation => $q->where('user_id', $participant->id)->with('versions.activityScore.dimensions')->latest('attempt_number')])->orderBy('position')->get();
        $rows = $activities->map(function (Activity $activity): array {
            $submission = $activity->submissions->first();
            $version = $submission?->versions->sortByDesc('version_number')->first(fn (SubmissionVersion $v): bool => in_array($v->status, ['submitted', 'resubmitted', 'approved', 'completed'], true)) ?? $submission?->versions->sortByDesc('version_number')->first();

            return ['activity' => $activity, 'submission' => $submission, 'version' => $version, 'score' => $version?->activityScore];
        });
        $scored = $rows->filter(fn (array $row): bool => $row['score']?->status === 'published' && $row['score']->total_score !== null);
        $completed = $rows->filter(fn (array $row): bool => in_array($row['submission']?->status, ['submitted', 'resubmitted', 'approved', 'completed'], true))->count();

        return ['rows' => $rows, 'completed' => $completed, 'total' => $rows->count(), 'scored' => $scored->count(), 'average' => $scored->isEmpty() ? null : (int) round($scored->avg(fn (array $row): int => $row['score']->total_score))];
    }

    public function summary(User $participant, $program): array
    {
        $weeks = $program->weeks()->whereIn('week_number', [2, 3, 4])->orderBy('position')->get()->mapWithKeys(fn (TrainingWeek $week): array => [$week->week_number => $this->weekly($participant, $week)]);
        $rows = $weeks->flatMap(fn (array $week): Collection => $week['rows']);
        $scored = $rows->filter(fn (array $row): bool => $row['score']?->status === 'published' && $row['score']->total_score !== null);

        return ['weeks' => $weeks, 'completed' => $rows->filter(fn (array $row): bool => in_array($row['submission']?->status, ['submitted', 'resubmitted', 'approved', 'completed'], true))->count(), 'total' => $rows->count(), 'scored' => $scored->count(), 'average' => $scored->isEmpty() ? null : (int) round($scored->avg(fn (array $row): int => $row['score']->total_score))];
    }

    public function runningAverage(User $participant, $exceptVersion = null): ?int
    {
        $scores = ActivityScore::query()->where('status', 'published')->whereHas('version.submission', fn (Builder $q): Builder => $q->where('user_id', $participant->id))->when($exceptVersion, fn (Builder $q): Builder => $q->where('submission_version_id', '!=', $exceptVersion))->pluck('total_score');

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }
}
