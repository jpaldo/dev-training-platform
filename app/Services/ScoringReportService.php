<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ScoringReportService
{
    /**
     * @param  array{participant?: int|string|null, path?: ?string, week?: int|string|null, activity?: int|string|null, assigned_coach?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Builder<SubmissionVersion>
     */
    public function versions(array $filters): Builder
    {
        return SubmissionVersion::query()->where('status', '!=', SubmissionStatus::Draft)
            ->with(['submission.participant.assignedCoaches', 'submission.activity.week', 'publishedActivityScore.scorer', 'publishedActivityScore.secondReader', 'publishedActivityScore.dimensions'])
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->where('user_id', $v)))
            ->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->whereHas('submission.participant', fn (Builder $p): Builder => $p->where('training_path', $v)))
            ->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission.activity.week', fn (Builder $w): Builder => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->where('activity_id', $v)))
            ->when($filters['assigned_coach'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission.participant.assignedCoaches', fn (Builder $c): Builder => $c->whereKey($v)))
            ->when(($filters['score_status'] ?? null) === 'scored', fn (Builder $q): Builder => $q->whereHas('publishedActivityScore'))
            ->when(($filters['score_status'] ?? null) === 'unscored', fn (Builder $q): Builder => $q->whereDoesntHave('publishedActivityScore'))
            ->when($filters['band'] ?? null, fn (Builder $q, string $v): Builder => $q->whereHas('publishedActivityScore', fn (Builder $s): Builder => $s->where('performance_band', $v)))
            ->when($filters['from'] ?? null, fn (Builder $q, string $v): Builder => $q->whereDate('submitted_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v): Builder => $q->whereDate('submitted_at', '<=', $v));
    }

    /**
     * @param  array{participant?: int|string|null, path?: ?string, week?: int|string|null, activity?: int|string|null, assigned_coach?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Collection<int, User>
     */
    public function participants(array $filters): Collection
    {
        return User::query()
            ->where('role', UserRole::Participant)
            ->with('assignedCoaches')
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereKey($v))
            ->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->where('training_path', $v))
            ->when($filters['assigned_coach'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('assignedCoaches', fn (Builder $c): Builder => $c->whereKey($v)))
            ->get();
    }
}
