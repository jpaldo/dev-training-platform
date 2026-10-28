<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachParticipantListPageService
{
    private const PARTICIPANTS_PER_PAGE = 25;

    public function __construct(private readonly CoachProgramFilterService $programFilter) {}

    /**
     * Everything the coach participants page renders: the filter values, the curriculum options and one row per
     * participant and curriculum. An admin sees every curriculum membership; a coach sees only those where they are
     * the participant's current mentor. The page has no single main action, so every button is secondary.
     *
     * @param  array{search?: ?string, program?: int|string|null}  $filters
     * @return array{search: string, programOptions: list<array{value: string, label: string, is_selected: bool}>, participants: LengthAwarePaginator<int, array{name: string, email: string, employee_id: string, program_name: string, program_dates: string, mentor_name: ?string, drive_url: ?string, google_drive_account: ?string, submission_count: int, gate_count: int, inspect_url: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        return [
            'search' => (string) ($filters['search'] ?? ''),
            'programOptions' => $this->programFilter->options($viewer, $filters['program'] ?? null),
            'participants' => $this->memberships($viewer, $filters)
                ->paginate(self::PARTICIPANTS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ProgramParticipant $membership): array => $this->participantRow($membership)),
        ];
    }

    /**
     * The curriculum memberships of participants the viewer may see (every one for an admin, otherwise those where the
     * viewer is the current mentor) that match the filters, ordered by participant name, then the latest first week
     * start (unscheduled curricula last) and the curriculum name, each with the current mentor's name and the
     * participant's submission and Gate evaluation counts in that curriculum. The curriculum is loaded with its
     * schedule bounds, so its dates need no query per row.
     *
     * @param  array{search?: ?string, program?: int|string|null}  $filters
     * @return Builder<ProgramParticipant>
     */
    private function memberships(User $viewer, array $filters): Builder
    {
        $memberships = ProgramParticipant::query();

        return $memberships
            ->select(['id', 'program_id', 'user_id'])
            ->addSelect([
                'mentor_name' => MentorAssignment::query()->currentMentorNameOfRow($memberships, 'program_participants.program_id'),
                'submission_count' => $this->workOfMembership(ActivitySubmission::query())->selectRaw('count(*)'),
                'gate_count' => $this->workOfMembership(GateEvaluation::query())->selectRaw('count(*)'),
            ])
            ->whereHas('participant', fn (Builder $participants): Builder => $participants
                ->where('role', UserRole::Participant)
                ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $matches): Builder => $matches
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->when($filters['program'] ?? null, fn (Builder $query, int|string $programId): Builder => $query->where('program_id', $programId))
            ->with([
                'participant' => fn (Relation $participants): Relation => $participants
                    ->select(['id', 'name', 'email', 'employee_id', 'drive_link'])
                    ->with('googleDriveConnection'),
                'program' => fn (Relation $programs): Relation => $programs->select(['id', 'name'])->withScheduleBounds(),
            ])
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'program_participants.user_id'))
            ->orderByDesc(TrainingWeek::query()
                ->selectRaw('min(starts_on)')
                ->whereColumn('training_weeks.program_id', 'program_participants.program_id')
                ->where('is_active', true))
            ->orderBy(Program::query()->select('name')->whereColumn('programs.id', 'program_participants.program_id'))
            ->orderBy('id');
    }

    /**
     * Limit a query on submissions or Gate evaluations to the outer membership query's participant in its curriculum
     * (through the submission's activity or the evaluation's Gate, and its week).
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
            ->whereColumn("{$table}.user_id", 'program_participants.user_id')
            ->whereHas(
                $query->getModel() instanceof ActivitySubmission ? 'activity.week' : 'gate.week',
                fn (Builder $weeks): Builder => $weeks->whereColumn('training_weeks.program_id', 'program_participants.program_id'),
            );
    }

    /**
     * One table row: the participant with their employee ID (or a dash), the curriculum and its dates, the current
     * mentor's name (null when not assigned), the Drive links and the counts in the curriculum.
     *
     * @return array{name: string, email: string, employee_id: string, program_name: string, program_dates: string, mentor_name: ?string, drive_url: ?string, google_drive_account: ?string, submission_count: int, gate_count: int, inspect_url: string}
     */
    private function participantRow(ProgramParticipant $membership): array
    {
        $participant = $membership->participant;

        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'employee_id' => $participant->employee_id ?? '—',
            'program_name' => $membership->program->name,
            'program_dates' => $membership->program->dateRangeLabel(),
            'mentor_name' => $membership->mentor_name,
            'drive_url' => $participant->drive_link ?: null,
            'google_drive_account' => $participant->googleDriveConnection
                ? ($participant->googleDriveConnection->google_email ?: 'Account connected')
                : null,
            'submission_count' => (int) $membership->submission_count,
            'gate_count' => (int) $membership->gate_count,
            'inspect_url' => route('coach.participants.show', [$membership->program, $participant]),
        ];
    }
}
