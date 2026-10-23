<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
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
    /** @var list<SubmissionStatus> The submission statuses that put a participant at risk. */
    private const AT_RISK_SUBMISSION_STATUSES = [SubmissionStatus::NeedsRevision, SubmissionStatus::Failed];

    public function __construct(private readonly WeekProgressionService $progression) {}

    /**
     * Headline counts of the coach dashboard; no participant has completed a program while none is active.
     *
     * @return array{participants: int, completed: int, needs_review: int, needs_revision: int, at_risk: int}
     */
    public function dashboardStats(?Program $activeProgram): array
    {
        $participants = $this->filteredParticipants()->get(['id']);

        return [
            'participants' => $participants->count(),
            'completed' => $activeProgram ? $this->progression->completedParticipantIds($participants, $activeProgram)->count() : 0,
            'needs_review' => $this->reviewQueue()->whereIn('status', SubmissionStatus::awaitingReview())->count(),
            'needs_revision' => $this->reviewQueue()->where('status', SubmissionStatus::NeedsRevision)->count(),
            'at_risk' => $this->atRiskParticipants()->count(),
        ];
    }

    /**
     * The participants matching the filters with their assigned coaches and their submission, Gate evaluation and
     * passed Gate evaluation counts.
     *
     * @param  ?array{search?: ?string, mentor?: int|string|null, assigned_coach?: int|string|null}  $filters
     * @return Builder<User>
     */
    public function participants(?array $filters = null): Builder
    {
        return $this->filteredParticipants($filters)
            ->withCount([
                'activitySubmissions',
                'gateEvaluations',
                'gateEvaluations as passed_gate_evaluations_count' => fn (Builder $query): Builder => $query->where('status', GateEvaluationStatus::Passed),
            ])
            ->with('assignedCoaches');
    }

    public function participantSummary(?array $filters = null): Builder
    {
        return $this->filteredParticipants($filters)
            ->withCount(['activitySubmissions', 'gateEvaluations as passed_gate_evaluations_count' => fn (Builder $query): Builder => $query->where('status', GateEvaluationStatus::Passed)]);
    }

    public function reviewQueue(?array $filters = null): Builder
    {
        return ActivitySubmission::query()->whereIn('status', [...SubmissionStatus::awaitingReview(), SubmissionStatus::NeedsRevision])
            ->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('user_id', $v))
            ->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('activity.week', fn (Builder $w): Builder => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('activity_id', $v))
            ->with(['participant', 'activity.week', 'latestVersion'])->latest('submitted_at');
    }

    /**
     * The participants matching the filters who have a submission needing revision or failed, or a Gate evaluation
     * that did not pass, in id order, with only those submissions (and their activity titles) and evaluations (and
     * their Gate titles) loaded.
     *
     * @param  ?array{search?: ?string, mentor?: int|string|null, assigned_coach?: int|string|null}  $filters
     * @return Builder<User>
     */
    public function atRiskParticipants(?array $filters = null): Builder
    {
        return $this->filteredParticipants($filters)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('activitySubmissions', fn (Builder $submissions): Builder => $submissions->whereIn('status', self::AT_RISK_SUBMISSION_STATUSES))
                ->orWhereHas('gateEvaluations', fn (Builder $evaluations): Builder => $evaluations->where('status', '!=', GateEvaluationStatus::Passed)))
            ->with([
                'activitySubmissions' => fn (Relation $submissions): Relation => $submissions->whereIn('status', self::AT_RISK_SUBMISSION_STATUSES)->with('activity:id,title'),
                'gateEvaluations' => fn (Relation $evaluations): Relation => $evaluations->where('status', '!=', GateEvaluationStatus::Passed)->with('gate:id,title'),
            ])
            ->orderBy('id');
    }

    /**
     * Why a participant loaded by atRiskParticipants() is at risk: "Activity: status" per submission, then "Gate:
     * status" per evaluation.
     *
     * @return list<string>
     */
    public function atRiskReasons(User $participant): array
    {
        return array_merge(
            $participant->activitySubmissions->map(fn (ActivitySubmission $submission): string => "{$submission->activity->title}: {$submission->status->label()}")->values()->all(),
            $participant->gateEvaluations->map(fn (GateEvaluation $evaluation): string => "{$evaluation->gate->title}: {$evaluation->status->label()}")->values()->all(),
        );
    }

    /**
     * Every at-risk participant matching the filters with their reasons.
     *
     * @param  ?array{search?: ?string, mentor?: int|string|null, assigned_coach?: int|string|null}  $filters
     * @return Collection<int, array{participant: User, reasons: list<string>}>
     */
    public function atRiskRows(?array $filters = null): Collection
    {
        return $this->atRiskParticipants($filters)->get()->map(fn (User $participant): array => [
            'participant' => $participant,
            'reasons' => $this->atRiskReasons($participant),
        ]);
    }

    public function gateResults(): Builder
    {
        return GateEvaluation::query()->with(['participant', 'gate.week'])->latest('evaluated_at');
    }

    public function filteredGateResults(?array $filters = null): Builder
    {
        return $this->gateResults()->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('user_id', $v))->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('gate.week', fn (Builder $w): Builder => $w->where('week_number', $v)));
    }

    public function reviewRows(?array $filters = null): Collection
    {
        return $this->reviewQueue($filters)->with('latestVersion.reviews')->get()->map(function (ActivitySubmission $submission): array {
            $version = $submission->latestVersion;
            $review = $version?->reviews->sortByDesc('reviewed_at')->first();

            return compact('submission', 'version', 'review');
        });
    }

    /**
     * Headline counts of the reports page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{participants: int, active: int, submissions: int, gates: int, at_risk: int}
     */
    public function reportSummary(array $filters, int $atRiskCount): array
    {
        $summaryRows = $this->participantSummary($filters)->get();

        return [
            'participants' => $summaryRows->count(),
            'active' => $summaryRows->where('is_active', true)->count(),
            'submissions' => $summaryRows->sum('activity_submissions_count'),
            'gates' => $summaryRows->sum(fn (User $participant): int => $participant->passed_gate_evaluations_count),
            'at_risk' => $atRiskCount,
        ];
    }

    /** @return Collection<int, User> */
    public function mentors(): Collection
    {
        return User::query()->whereIn('role', [UserRole::Coach, UserRole::Admin])->orderBy('name')->get();
    }

    /**
     * The active weeks of the participants' program, in week order. Participants are not linked to a program, so this
     * is the active program the participant dashboard shows (the first by id); no weeks while none is active.
     *
     * @return Collection<int, TrainingWeek>
     */
    public function activeProgramWeeks(): Collection
    {
        return TrainingWeek::query()->inActiveProgram()->orderBy('week_number')->get();
    }

    /**
     * Build a coach report CSV: participant progress by default, or the review queue, Gate results or at-risk list.
     *
     * @param  array<string, mixed>  $input  the request's report filters
     * @return array{filename: string, header: list<string>, rows: Collection<int, list<mixed>>}
     */
    public function csvExport(string $type, array $input): array
    {
        $filters = Arr::only($input, ['week', 'activity', 'status', 'participant']);

        return match ($type) {
            'reviews' => [
                'filename' => 'review-queue.csv',
                'header' => ['Participant', 'Employee ID', 'Week', 'Activity', 'Status', 'Version', 'Submitted', 'Decision', 'Reviewed'],
                'rows' => $this->reviewRows($filters)->map(fn (array $r): array => [$r['submission']->participant->name, $r['submission']->participant->employee_id, $r['submission']->activity->week->week_number, $r['submission']->activity->title, $r['submission']->status->value, $r['version']?->version_number, $r['submission']->submitted_at, $r['review']?->decision->value, $r['review']?->reviewed_at]),
            ],
            'gates' => [
                'filename' => 'gate-results.csv',
                'header' => ['Participant', 'Employee ID', 'Week', 'Gate', 'Status', 'Evaluated', 'Reviewer'],
                'rows' => $this->filteredGateResults($filters)->get()->map(fn (GateEvaluation $e): array => [$e->participant->name, $e->participant->employee_id, $e->gate->week->week_number, $e->gate->title, $e->status->value, $e->evaluated_at, $e->reviewer_id]),
            ],
            'risk' => [
                'filename' => 'at-risk.csv',
                'header' => ['Participant', 'Reason'],
                'rows' => $this->atRiskRows($filters)->map(fn (array $r): array => [$r['participant']->name, implode('; ', $r['reasons'])]),
            ],
            default => [
                'filename' => 'participant-progress.csv',
                'header' => ['Name', 'Employee ID', 'Email', 'Submissions', 'Gate evaluations'],
                'rows' => $this->participants(Arr::only($input, ['search']))->get()
                    ->when($input['mentor'] ?? null, fn (Collection $items, int|string $mentor): Collection => $items->filter(fn (User $participant): bool => $participant->assignedCoaches->contains('id', (int) $mentor))->values())
                    ->map(fn (User $item): array => [$item->name, $item->employee_id, $item->email, $item->activity_submissions_count, $item->gate_evaluations_count]),
            ],
        };
    }

    /**
     * The participants matching the name or email search and mentor filters.
     *
     * @param  ?array{search?: ?string, mentor?: int|string|null, assigned_coach?: int|string|null}  $filters
     * @return Builder<User>
     */
    private function filteredParticipants(?array $filters = null): Builder
    {
        return User::query()->where('role', UserRole::Participant)
            ->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $search): Builder => $search->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($filters['mentor'] ?? $filters['assigned_coach'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($v)));
    }
}
