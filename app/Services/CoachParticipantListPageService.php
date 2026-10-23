<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachParticipantListPageService
{
    private const PARTICIPANTS_PER_PAGE = 25;

    public function __construct(private readonly CoachSessionFilterService $sessionFilter) {}

    /**
     * Everything the coach participants page renders: the filter values, the session options and one row per
     * participant and training session. An admin sees every session membership; a coach sees only those where they
     * are the participant's current mentor. The page has no single main action, so every button is secondary.
     *
     * @param  array{search?: ?string, session?: int|string|null}  $filters
     * @return array{search: string, sessionOptions: list<array{value: string, label: string, is_selected: bool}>, participants: LengthAwarePaginator<int, array{name: string, email: string, employee_id: string, session_title: string, session_dates: string, mentor_name: ?string, drive_url: ?string, google_drive_account: ?string, attendance: ?array{is_open: bool, status: string, time: string, duration: string}, submission_count: int, gate_count: int, inspect_url: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        return [
            'search' => (string) ($filters['search'] ?? ''),
            'sessionOptions' => $this->sessionFilter->options($viewer, $filters['session'] ?? null),
            'participants' => $this->memberships($viewer, $filters)
                ->paginate(self::PARTICIPANTS_PER_PAGE)
                ->withQueryString()
                ->through(fn (TrainingSessionParticipant $membership): array => $this->participantRow($membership)),
        ];
    }

    /**
     * The session memberships of participants the viewer may see (every one for an admin, otherwise those where the
     * viewer is the current mentor) that match the filters, ordered by participant name and then the latest session
     * first, each with the current mentor's name and the participant's submission and Gate evaluation counts in that
     * session.
     *
     * @param  array{search?: ?string, session?: int|string|null}  $filters
     * @return Builder<TrainingSessionParticipant>
     */
    private function memberships(User $viewer, array $filters): Builder
    {
        $memberships = TrainingSessionParticipant::query();

        return $memberships
            ->select(['id', 'training_session_id', 'user_id'])
            ->addSelect([
                'mentor_name' => MentorAssignment::query()->currentMentorNameOfRow($memberships),
                'submission_count' => $this->workOfMembership(ActivitySubmission::query())->selectRaw('count(*)'),
                'gate_count' => $this->workOfMembership(GateEvaluation::query())->selectRaw('count(*)'),
            ])
            ->whereHas('participant', fn (Builder $participants): Builder => $participants
                ->where('role', UserRole::Participant)
                ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $matches): Builder => $matches
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->when($filters['session'] ?? null, fn (Builder $query, int|string $trainingSessionId): Builder => $query->where('training_session_id', $trainingSessionId))
            ->with([
                'participant' => fn (Relation $participants): Relation => $participants
                    ->select(['id', 'name', 'email', 'employee_id', 'drive_link'])
                    ->with(['attendanceSessions' => fn (Relation $sessions): Relation => $sessions->latest('check_in_at'), 'googleDriveConnection']),
                'trainingSession:id,title,starts_on,ends_on',
            ])
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'training_session_participants.user_id'))
            ->orderByDesc(TrainingSession::query()->select('starts_on')->whereColumn('training_sessions.id', 'training_session_participants.training_session_id'))
            ->orderBy('id');
    }

    /**
     * Limit a query on a table with `training_session_id` and `user_id` to the outer query's membership.
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

    /**
     * One table row: the participant with their employee ID (or a dash), the session and its dates, the current
     * mentor's name (null when not assigned), the Drive links, the counts in the session and the open (or else latest)
     * attendance session.
     *
     * @return array{name: string, email: string, employee_id: string, session_title: string, session_dates: string, mentor_name: ?string, drive_url: ?string, google_drive_account: ?string, attendance: ?array{is_open: bool, status: string, time: string, duration: string}, submission_count: int, gate_count: int, inspect_url: string}
     */
    private function participantRow(TrainingSessionParticipant $membership): array
    {
        $participant = $membership->participant;

        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'employee_id' => $participant->employee_id ?? '—',
            'session_title' => $membership->trainingSession->title,
            'session_dates' => $membership->trainingSession->dateRangeLabel(),
            'mentor_name' => $membership->mentor_name,
            'drive_url' => $participant->drive_link ?: null,
            'google_drive_account' => $participant->googleDriveConnection
                ? ($participant->googleDriveConnection->google_email ?: 'Account connected')
                : null,
            'attendance' => $this->attendanceSummary($participant),
            'submission_count' => (int) $membership->submission_count,
            'gate_count' => (int) $membership->gate_count,
            'inspect_url' => route('coach.participants.show', [$membership->trainingSession, $participant]),
        ];
    }

    /**
     * The session still checked in (with its check-in time and elapsed time), or else the latest session (with its
     * check-out time and duration), or null when the participant never checked in.
     *
     * @return ?array{is_open: bool, status: string, time: string, duration: string}
     */
    private function attendanceSummary(User $participant): ?array
    {
        $openSession = $participant->attendanceSessions->firstWhere('check_out_at', null);

        if ($openSession instanceof AttendanceSession) {
            return [
                'is_open' => true,
                'status' => 'CHECKED IN',
                'time' => $openSession->check_in_at->format('M j · g:i A'),
                'duration' => "{$openSession->durationLabel()} elapsed",
            ];
        }

        $latestSession = $participant->attendanceSessions->first();

        if (! $latestSession instanceof AttendanceSession) {
            return null;
        }

        return [
            'is_open' => false,
            'status' => 'CHECKED OUT',
            'time' => (string) $latestSession->check_out_at?->format('M j · g:i A'),
            'duration' => $latestSession->durationLabel(),
        ];
    }
}
