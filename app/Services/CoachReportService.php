<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class CoachReportService
{
    public function __construct(private readonly WeekProgressionService $progression) {}

    /**
     * Headline counts of the coach dashboard; no participant has completed a program while none is active.
     *
     * @return array{participants: int, completed: int, needs_review: int, needs_revision: int, at_risk: int}
     */
    public function dashboardStats(?Program $activeProgram): array
    {
        $participants = $this->participants()->get();

        return [
            'participants' => $participants->count(),
            'completed' => $activeProgram ? $this->progression->completedParticipantIds($participants, $activeProgram)->count() : 0,
            'needs_review' => $this->reviewQueue()->whereIn('status', SubmissionStatus::awaitingReview())->count(),
            'needs_revision' => $this->reviewQueue()->where('status', SubmissionStatus::NeedsRevision)->count(),
            'at_risk' => $this->atRisk()->count(),
        ];
    }

    public function participants(?array $filters = null): Builder
    {
        return User::query()->where('role', UserRole::Participant)
            ->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->where('training_path', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $search): Builder => $search->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($filters['mentor'] ?? $filters['assigned_coach'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($v)))
            ->withCount('dailyLogs')
            ->with(['activitySubmissions.activity.week', 'activitySubmissions.versions.reviews', 'gateEvaluations.gate', 'attendanceSessions' => fn (Relation $q): Relation => $q->latest('check_in_at'), 'googleDriveConnection', 'assignedCoaches']);
    }

    public function participantSummary(?array $filters = null): Builder
    {
        return User::query()->where('role', UserRole::Participant)
            ->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->where('training_path', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $search): Builder => $search->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($filters['mentor'] ?? $filters['assigned_coach'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($v)))
            ->withCount(['activitySubmissions', 'gateEvaluations as passed_gate_evaluations_count' => fn (Builder $query): Builder => $query->where('status', GateEvaluationStatus::Passed)]);
    }

    public function reviewQueue(?array $filters = null): Builder
    {
        return ActivitySubmission::query()->whereIn('status', [...SubmissionStatus::awaitingReview(), SubmissionStatus::NeedsRevision])
            ->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('user_id', $v))
            ->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('activity.week', fn (Builder $w): Builder => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('activity_id', $v))
            ->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->whereHas('participant', fn (Builder $u): Builder => $u->where('training_path', $v)))
            ->with(['participant', 'activity.week', 'versions' => fn (Relation $q): Relation => $q->with(['evidence', 'reviews'])->latest('version_number')])->latest('submitted_at');
    }

    public function atRiskRows(?array $filters = null): Collection
    {
        return $this->participants($filters)->get()->map(function (User $participant): ?array {
            $reasons = $participant->activitySubmissions->filter(fn (ActivitySubmission $s): bool => $s->needsRevision() || $s->isFailed())->map(fn (ActivitySubmission $s): string => "{$s->activity->title}: {$s->status->label()}")->values()->all();
            $reasons = array_merge($reasons, $participant->gateEvaluations->filter(fn (GateEvaluation $g): bool => $g->status !== GateEvaluationStatus::Passed)->map(fn (GateEvaluation $g): string => "{$g->gate->title}: {$g->status->label()}")->values()->all());

            return $reasons === [] ? null : ['participant' => $participant, 'reasons' => $reasons];
        })->filter()->values();
    }

    public function atRisk(): Collection
    {
        return $this->atRiskRows()->map(fn (array $row): User => $row['participant']);
    }

    public function gateResults(): Builder
    {
        return GateEvaluation::query()->with(['participant', 'gate.week', 'criterionEvaluations.criterion'])->latest('evaluated_at');
    }

    public function filteredGateResults(?array $filters = null): Builder
    {
        return $this->gateResults()->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('user_id', $v))->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->whereHas('participant', fn (Builder $u): Builder => $u->where('training_path', $v)))->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('gate.week', fn (Builder $w): Builder => $w->where('week_number', $v)));
    }

    public function reviewRows(?array $filters = null): Collection
    {
        return $this->reviewQueue($filters)->get()->map(function (ActivitySubmission $submission): array {
            $version = $submission->versions->first();
            $review = $version?->reviews->sortByDesc('reviewed_at')->first();

            return compact('submission', 'version', 'review');
        });
    }

    /**
     * At-risk rows for the reports page, limited to the participants of the selected mentor.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{participant: User, reasons: list<string>}>
     */
    public function reportAtRiskRows(array $filters): Collection
    {
        return $this->atRiskRows($filters)->when($filters['mentor'] ?? null, fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['participant']->assignedCoaches->contains('id', (int) $filters['mentor']))->values());
    }

    /**
     * Headline counts of the reports page.
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, array{participant: User, reasons: list<string>}>  $atRiskRows
     * @return array{participants: int, active: int, submissions: int, gates: int, at_risk: int}
     */
    public function reportSummary(array $filters, Collection $atRiskRows): array
    {
        $summaryRows = $this->participantSummary($filters)->get();

        return [
            'participants' => $summaryRows->count(),
            'active' => $summaryRows->where('is_active', true)->count(),
            'submissions' => $summaryRows->sum('activity_submissions_count'),
            'gates' => $summaryRows->sum(fn (User $participant): int => $participant->passed_gate_evaluations_count),
            'at_risk' => $atRiskRows->count(),
        ];
    }

    /** @return Collection<int, User> */
    public function mentors(): Collection
    {
        return User::query()->whereIn('role', [UserRole::Coach, UserRole::Admin])->orderBy('name')->get();
    }

    /** @return Collection<int, TrainingWeek> */
    public function activeWeeks(): Collection
    {
        return TrainingWeek::query()->where('is_active', true)->orderBy('week_number')->get();
    }

    /**
     * A participant's daily logs, newest first, optionally limited to one week and one date.
     *
     * @return Builder<DailyLog>
     */
    public function participantDailyLogs(User $participant, ?TrainingWeek $week, ?string $date): Builder
    {
        return DailyLog::query()
            ->where('user_id', $participant->id)
            ->with(['activity.week'])
            ->when($week, fn (Builder $query): Builder => $query->whereHas('activity', fn (Builder $activity): Builder => $activity->where('training_week_id', $week->id)))
            ->when($date, fn (Builder $query, string $date): Builder => $query->whereDate('logged_on', $date))
            ->latest('logged_on')
            ->latest('id');
    }

    /**
     * Build a coach report CSV: participant progress by default, or the review queue, Gate results or at-risk list.
     *
     * @param  array<string, mixed>  $input  the request's report filters
     * @return array{filename: string, header: list<string>, rows: Collection<int, list<mixed>>}
     */
    public function csvExport(string $type, array $input): array
    {
        $filters = Arr::only($input, ['path', 'week', 'activity', 'status', 'participant']);

        return match ($type) {
            'reviews' => [
                'filename' => 'review-queue.csv',
                'header' => ['Participant', 'Path', 'Week', 'Activity', 'Status', 'Version', 'Submitted', 'Decision', 'Reviewed'],
                'rows' => $this->reviewRows($filters)->map(fn (array $r): array => [$r['submission']->participant->name, $r['submission']->participant->trainingPathLabel(), $r['submission']->activity->week->week_number, $r['submission']->activity->title, $r['submission']->status->value, $r['version']?->version_number, $r['submission']->submitted_at, $r['review']?->decision->value, $r['review']?->reviewed_at]),
            ],
            'gates' => [
                'filename' => 'gate-results.csv',
                'header' => ['Participant', 'Path', 'Week', 'Gate', 'Status', 'Evaluated', 'Reviewer'],
                'rows' => $this->filteredGateResults($filters)->get()->map(fn (GateEvaluation $e): array => [$e->participant->name, $e->participant->trainingPathLabel(), $e->gate->week->week_number, $e->gate->title, $e->status->value, $e->evaluated_at, $e->reviewer_id]),
            ],
            'risk' => [
                'filename' => 'at-risk.csv',
                'header' => ['Participant', 'Path', 'Reason'],
                'rows' => $this->atRiskRows($filters)->map(fn (array $r): array => [$r['participant']->name, $r['participant']->trainingPathLabel(), implode('; ', $r['reasons'])]),
            ],
            default => [
                'filename' => 'participant-progress.csv',
                'header' => ['Name', 'Email', 'Training path', 'Submissions', 'Gate evaluations'],
                'rows' => $this->participants(Arr::only($input, ['path', 'search']))->get()
                    ->when($input['mentor'] ?? null, fn (Collection $items, int|string $mentor): Collection => $items->filter(fn (User $participant): bool => $participant->assignedCoaches->contains('id', (int) $mentor))->values())
                    ->map(fn (User $item): array => [$item->name, $item->email, $item->trainingPathLabel(), $item->activitySubmissions->count(), $item->gateEvaluations->count()]),
            ],
        };
    }
}
