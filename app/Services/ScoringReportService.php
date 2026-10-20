<?php

namespace App\Services;

use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScoringReportService
{
    public function versions(array $filters): Builder
    {
        return SubmissionVersion::query()->where('status', '!=', 'draft')
            ->with(['submission.participant.assignedCoaches', 'submission.activity.week', 'activityScore.scorer', 'activityScore.secondReader', 'activityScore.dimensions'])
            ->when($filters['participant'] ?? null, fn ($q, $v) => $q->whereHas('submission', fn ($s) => $s->where('user_id', $v)))
            ->when($filters['path'] ?? null, fn ($q, $v) => $q->whereHas('submission.participant', fn ($p) => $p->where('training_path', $v)))
            ->when($filters['week'] ?? null, fn ($q, $v) => $q->whereHas('submission.activity.week', fn ($w) => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn ($q, $v) => $q->whereHas('submission', fn ($s) => $s->where('activity_id', $v)))
            ->when($filters['assigned_coach'] ?? null, fn ($q, $v) => $q->whereHas('submission.participant.assignedCoaches', fn ($c) => $c->whereKey($v)))
            ->when(($filters['score_status'] ?? null) === 'scored', fn ($q) => $q->whereHas('activityScore', fn ($s) => $s->where('status', 'published')))
            ->when(($filters['score_status'] ?? null) === 'unscored', fn ($q) => $q->whereDoesntHave('activityScore'))
            ->when($filters['band'] ?? null, fn ($q, $v) => $q->whereHas('activityScore', fn ($s) => $s->where('performance_band', $v)))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('submitted_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('submitted_at', '<=', $v));
    }

    public function participants(array $filters)
    {
        return User::query()->where('role', 'participant')->with('assignedCoaches')->when($filters['participant'] ?? null, fn ($q, $v) => $q->whereKey($v))->when($filters['path'] ?? null, fn ($q, $v) => $q->where('training_path', $v))->when($filters['assigned_coach'] ?? null, fn ($q, $v) => $q->whereHas('assignedCoaches', fn ($c) => $c->whereKey($v)))->get();
    }
}
