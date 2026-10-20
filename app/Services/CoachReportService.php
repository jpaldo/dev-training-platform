<?php

namespace App\Services;

use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CoachReportService
{
    public function participants(?array $filters = null): Builder
    {
        return User::query()->where('role', 'participant')
            ->when($filters['path'] ?? null, fn ($q, $v) => $q->where('training_path', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($search) => $search->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($filters['mentor'] ?? $filters['assigned_coach'] ?? null, fn ($q, $v) => $q->whereHas('assignedCoaches', fn ($coach) => $coach->whereKey($v)))
            ->withCount('dailyLogs')
            ->with(['activitySubmissions.activity.week', 'activitySubmissions.versions.reviews', 'gateEvaluations.gate', 'attendanceSessions' => fn ($q) => $q->latest('check_in_at'), 'googleDriveConnection', 'assignedCoaches']);
    }

    public function participantSummary(?array $filters = null): Builder
    {
        return User::query()->where('role', 'participant')
            ->when($filters['path'] ?? null, fn ($q, $v) => $q->where('training_path', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($search) => $search->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($filters['mentor'] ?? $filters['assigned_coach'] ?? null, fn ($q, $v) => $q->whereHas('assignedCoaches', fn ($coach) => $coach->whereKey($v)))
            ->withCount(['activitySubmissions', 'gateEvaluations as passed_gate_evaluations_count' => fn ($query) => $query->where('status', 'passed')]);
    }

    public function reviewQueue(?array $filters = null): Builder
    {
        return ActivitySubmission::query()->whereIn('status', ['submitted', 'resubmitted', 'under_review', 'needs_revision'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['participant'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['week'] ?? null, fn ($q, $v) => $q->whereHas('activity.week', fn ($w) => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn ($q, $v) => $q->where('activity_id', $v))
            ->when($filters['path'] ?? null, fn ($q, $v) => $q->whereHas('participant', fn ($u) => $u->where('training_path', $v)))
            ->with(['participant', 'activity.week', 'versions' => fn ($q) => $q->with(['evidence', 'reviews'])->latest('version_number')])->latest('submitted_at');
    }

    public function atRiskRows(?array $filters = null): Collection
    {
        return $this->participants($filters)->get()->map(function ($participant) {
            $reasons = $participant->activitySubmissions->filter(fn ($s) => in_array($s->status, ['needs_revision', 'failed'], true))->map(fn ($s) => "Activity {$s->activity_id}: {$s->status}")->values()->all();
            $reasons = array_merge($reasons, $participant->gateEvaluations->filter(fn ($g) => $g->status !== 'passed')->map(fn ($g) => "Gate {$g->training_gate_id}: {$g->status}")->values()->all());

            return $reasons === [] ? null : ['participant' => $participant, 'reasons' => $reasons];
        })->filter()->values();
    }

    public function atRisk(): Collection
    {
        return $this->atRiskRows()->map(fn ($row) => $row['participant']);
    }

    public function gateResults(): Builder
    {
        return GateEvaluation::query()->with(['participant', 'gate.week', 'criterionEvaluations.criterion'])->latest('evaluated_at');
    }

    public function filteredGateResults(?array $filters = null): Builder
    {
        return $this->gateResults()->when($filters['participant'] ?? null, fn ($q, $v) => $q->where('user_id', $v))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['path'] ?? null, fn ($q, $v) => $q->whereHas('participant', fn ($u) => $u->where('training_path', $v)))->when($filters['week'] ?? null, fn ($q, $v) => $q->whereHas('gate.week', fn ($w) => $w->where('week_number', $v)));
    }

    public function reviewRows(?array $filters = null): Collection
    {
        return $this->reviewQueue($filters)->get()->map(function ($submission) {
            $version = $submission->versions->first();
            $review = $version?->reviews->sortByDesc('reviewed_at')->first();

            return compact('submission', 'version', 'review');
        });
    }
}
