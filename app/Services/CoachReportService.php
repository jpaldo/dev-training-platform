<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingSessionParticipant;
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

    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly ScoringReportService $scoringReports,
    ) {}

    /**
     * Headline counts of the coach dashboard for the viewer, each counting what the page its stat links to lists: the
     * session memberships they may see (every one for an admin, otherwise those where they are the participant's
     * current mentor), how many of those memberships passed every week of their session, their review queue awaiting
     * review and needing revision, and their at-risk memberships.
     *
     * @return array{participants: int, completed: int, needs_review: int, needs_revision: int, at_risk: int}
     */
    public function dashboardStats(User $viewer): array
    {
        $memberships = TrainingSessionParticipant::query()
            ->whereHas('participant', fn (Builder $participants): Builder => $participants->where('role', UserRole::Participant))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->with('trainingSession:id,program_id,weeks_count')
            ->get(['id', 'training_session_id', 'user_id']);

        return [
            'participants' => $memberships->count(),
            'completed' => $this->progression->completedMemberships($memberships)->count(),
            'needs_review' => $this->reviewQueue($viewer)->whereIn('status', SubmissionStatus::awaitingReview())->count(),
            'needs_revision' => $this->reviewQueue($viewer)->where('status', SubmissionStatus::NeedsRevision)->count(),
            'at_risk' => $this->atRiskMemberships($viewer)->count(),
        ];
    }

    /**
     * The session memberships the viewer may see that match the search, session and mentor filters
     * (ScoringReportService::memberships()), one per participant and session, each with the participant's
     * submission, Gate evaluation and passed Gate evaluation counts in that session (`submission_count`,
     * `gate_evaluation_count`, `passed_gate_count`).
     *
     * @param  array{search?: ?string, session?: int|string|null, mentor?: int|string|null}  $filters
     * @return Builder<TrainingSessionParticipant>
     */
    public function progressMemberships(User $viewer, array $filters = []): Builder
    {
        return $this->scoringReports->memberships($viewer, Arr::only($filters, ['search', 'session', 'mentor']))
            ->addSelect([
                'submission_count' => $this->workOfMembership(ActivitySubmission::query())->selectRaw('count(*)'),
                'gate_evaluation_count' => $this->workOfMembership(GateEvaluation::query())->selectRaw('count(*)'),
                'passed_gate_count' => $this->workOfMembership(GateEvaluation::query())
                    ->where('status', GateEvaluationStatus::Passed)
                    ->selectRaw('count(*)'),
            ]);
    }

    /**
     * The submissions awaiting review or needing revision that the viewer may review (every one for an admin,
     * otherwise those whose participant the viewer currently mentors in the submission's session) and that match the
     * filters, newest submission first, with the participant, activity and week, training session and latest version.
     *
     * @param  array{status?: ?string, participant?: int|string|null, week?: int|string|null, activity?: int|string|null, session?: int|string|null}  $filters
     * @return Builder<ActivitySubmission>
     */
    public function reviewQueue(User $viewer, array $filters = []): Builder
    {
        return $this->reviewableSubmissions()
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->when($filters['session'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('training_session_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('user_id', $v))
            ->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('activity.week', fn (Builder $w): Builder => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('activity_id', $v))
            ->with(['participant', 'activity.week', 'latestVersion', 'trainingSession:id,title,starts_on,ends_on'])->latest('submitted_at');
    }

    /**
     * The session memberships the viewer may see that match the search, session and mentor filters
     * (ScoringReportService::memberships()) where the participant has, in that session, a submission needing revision
     * or failed, or a Gate evaluation that did not pass. The participant's submissions in those statuses (with their
     * activity titles) and evaluations that did not pass (with their Gate titles) are loaded for atRiskReasons().
     *
     * @param  array{search?: ?string, session?: int|string|null, mentor?: int|string|null}  $filters
     * @return Builder<TrainingSessionParticipant>
     */
    public function atRiskMemberships(User $viewer, array $filters = []): Builder
    {
        return $this->scoringReports->memberships($viewer, Arr::only($filters, ['search', 'session', 'mentor']))
            ->where(fn (Builder $query): Builder => $query
                ->whereExists($this->workOfMembership(ActivitySubmission::query())->whereIn('status', self::AT_RISK_SUBMISSION_STATUSES))
                ->orWhereExists($this->workOfMembership(GateEvaluation::query())->where('status', '!=', GateEvaluationStatus::Passed)))
            ->with(['participant' => fn (Relation $participants): Relation => $participants
                ->select(['id', 'name', 'email', 'employee_id', 'is_active'])
                ->with([
                    'activitySubmissions' => fn (Relation $submissions): Relation => $submissions
                        ->whereIn('status', self::AT_RISK_SUBMISSION_STATUSES)
                        ->with('activity:id,title'),
                    'gateEvaluations' => fn (Relation $evaluations): Relation => $evaluations
                        ->where('status', '!=', GateEvaluationStatus::Passed)
                        ->with('gate:id,title'),
                ])]);
    }

    /**
     * Why a membership loaded by atRiskMemberships() is at risk, from the participant's work in its session only:
     * "Activity: status" per submission, then "Gate: status" per evaluation.
     *
     * @return list<string>
     */
    public function atRiskReasons(TrainingSessionParticipant $membership): array
    {
        $participant = $membership->participant;

        return array_merge(
            $participant->activitySubmissions
                ->where('training_session_id', $membership->training_session_id)
                ->map(fn (ActivitySubmission $submission): string => "{$submission->activity->title}: {$submission->status->label()}")
                ->values()
                ->all(),
            $participant->gateEvaluations
                ->where('training_session_id', $membership->training_session_id)
                ->map(fn (GateEvaluation $evaluation): string => "{$evaluation->gate->title}: {$evaluation->status->label()}")
                ->values()
                ->all(),
        );
    }

    /**
     * Every at-risk membership the viewer may see that matches the filters, with its reasons.
     *
     * @param  array{search?: ?string, session?: int|string|null, mentor?: int|string|null}  $filters
     * @return Collection<int, array{membership: TrainingSessionParticipant, reasons: list<string>}>
     */
    public function atRiskRows(User $viewer, array $filters = []): Collection
    {
        return $this->atRiskMemberships($viewer, $filters)->get()->map(fn (TrainingSessionParticipant $membership): array => [
            'membership' => $membership,
            'reasons' => $this->atRiskReasons($membership),
        ]);
    }

    public function gateResults(): Builder
    {
        return GateEvaluation::query()->with(['participant', 'gate.week'])->latest('evaluated_at');
    }

    /**
     * The Gate evaluations the viewer may see (every one for an admin, otherwise those whose participant the viewer
     * currently mentors in the evaluation's session) that match the filters, latest evaluation first, with the
     * participant, Gate and week, and training session.
     *
     * @param  array{participant?: int|string|null, status?: ?string, week?: int|string|null, session?: int|string|null}  $filters
     * @return Builder<GateEvaluation>
     */
    public function filteredGateResults(User $viewer, array $filters = []): Builder
    {
        return $this->gateResults()
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->when($filters['session'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('training_session_id', $v))
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->where('user_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))
            ->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('gate.week', fn (Builder $w): Builder => $w->where('week_number', $v)))
            ->with('trainingSession:id,title,starts_on,ends_on');
    }

    /**
     * The viewer's review queue matching the filters (see reviewQueue()), each submission with its latest version and
     * that version's latest review.
     *
     * @param  array{status?: ?string, participant?: int|string|null, week?: int|string|null, activity?: int|string|null, session?: int|string|null}  $filters
     * @return Collection<int, array{submission: ActivitySubmission, version: ?SubmissionVersion, review: ?SubmissionReview}>
     */
    public function reviewRows(User $viewer, array $filters = []): Collection
    {
        return $this->reviewQueue($viewer, $filters)->with('latestVersion.reviews')->get()->map(function (ActivitySubmission $submission): array {
            $version = $submission->latestVersion;
            $review = $version?->reviews->sortByDesc('reviewed_at')->first();

            return compact('submission', 'version', 'review');
        });
    }

    /**
     * Headline counts of the progress report over the viewer's session memberships matching the filters (see
     * progressMemberships()): the memberships, those whose participant is active, their submissions and passed Gate
     * evaluations in their sessions, and the given at-risk count.
     *
     * @param  array{search?: ?string, session?: int|string|null, mentor?: int|string|null}  $filters
     * @return array{participants: int, active: int, submissions: int, gates: int, at_risk: int}
     */
    public function reportSummary(User $viewer, array $filters, int $atRiskCount): array
    {
        $memberships = $this->progressMemberships($viewer, $filters)->get();

        return [
            'participants' => $memberships->count(),
            'active' => $memberships->filter(fn (TrainingSessionParticipant $membership): bool => (bool) $membership->participant->is_active)->count(),
            'submissions' => $memberships->sum(fn (TrainingSessionParticipant $membership): int => (int) $membership->submission_count),
            'gates' => $memberships->sum(fn (TrainingSessionParticipant $membership): int => (int) $membership->passed_gate_count),
            'at_risk' => $atRiskCount,
        ];
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
     * Build a coach report CSV, each listing only what the viewer may see: participant progress per session membership
     * by default (see progressMemberships()), or the review queue (see reviewQueue()), Gate results (see
     * filteredGateResults()) or the at-risk memberships (see atRiskMemberships()).
     *
     * @param  array<string, mixed>  $input  the request's report filters
     * @return array{filename: string, header: list<string>, rows: Collection<int, list<mixed>>}
     */
    public function csvExport(User $viewer, string $type, array $input): array
    {
        $filters = Arr::only($input, ['week', 'activity', 'status', 'participant', 'session']);

        return match ($type) {
            'reviews' => [
                'filename' => 'review-queue.csv',
                'header' => ['Participant', 'Employee ID', 'Training session', 'Week', 'Activity', 'Status', 'Version', 'Submitted', 'Decision', 'Reviewed'],
                'rows' => $this->reviewRows($viewer, $filters)->map(fn (array $r): array => [$r['submission']->participant->name, $r['submission']->participant->employee_id, $r['submission']->trainingSession->titleWithDates(), $r['submission']->activity->week->week_number, $r['submission']->activity->title, $r['submission']->status->value, $r['version']?->version_number, $r['submission']->submitted_at, $r['review']?->decision->value, $r['review']?->reviewed_at]),
            ],
            'gates' => [
                'filename' => 'gate-results.csv',
                'header' => ['Participant', 'Employee ID', 'Training session', 'Week', 'Gate', 'Status', 'Evaluated', 'Reviewer'],
                'rows' => $this->filteredGateResults($viewer, $filters)->get()->map(fn (GateEvaluation $e): array => [$e->participant->name, $e->participant->employee_id, $e->trainingSession->titleWithDates(), $e->gate->week->week_number, $e->gate->title, $e->status->value, $e->evaluated_at, $e->reviewer_id]),
            ],
            'risk' => [
                'filename' => 'at-risk.csv',
                'header' => ['Participant', 'Training session', 'Reason'],
                'rows' => $this->atRiskRows($viewer, $input)->map(fn (array $r): array => [$r['membership']->participant->name, $r['membership']->trainingSession->titleWithDates(), implode('; ', $r['reasons'])]),
            ],
            default => [
                'filename' => 'participant-progress.csv',
                'header' => ['Name', 'Employee ID', 'Training session', 'Email', 'Submissions', 'Gate evaluations'],
                'rows' => $this->progressMemberships($viewer, $input)->get()
                    ->map(fn (TrainingSessionParticipant $m): array => [$m->participant->name, $m->participant->employee_id, $m->trainingSession->titleWithDates(), $m->participant->email, (int) $m->submission_count, (int) $m->gate_evaluation_count]),
            ],
        };
    }

    /**
     * Every submission awaiting review or needing revision.
     *
     * @return Builder<ActivitySubmission>
     */
    private function reviewableSubmissions(): Builder
    {
        return ActivitySubmission::query()->whereIn('status', [...SubmissionStatus::awaitingReview(), SubmissionStatus::NeedsRevision]);
    }

    /**
     * Limit a query on a table with `training_session_id` and `user_id` to the outer membership query's participant
     * in its session.
     *
     * @template TModel of ActivitySubmission|GateEvaluation
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function workOfMembership(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->whereColumn("{$table}.user_id", 'training_session_participants.user_id')
            ->whereColumn("{$table}.training_session_id", 'training_session_participants.training_session_id');
    }
}
