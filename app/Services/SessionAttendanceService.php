<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\User;
use App\Models\WeeklySessionAttendance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Weekly session attendance of a curriculum: which participants the viewer sees and which active weeks' sessions they
 * attended. Only attended sessions have a record (WeeklySessionAttendance).
 */
class SessionAttendanceService
{
    /**
     * The curriculum's memberships the viewer sees, by participant name, then participant id: every participant's for an
     * admin, otherwise only those whose current mentor in the curriculum is the viewer. Only users with the Participant
     * role are listed (disabled ones too). Each row has its participant (`id,name,email,employee_id`) and the
     * `mentor_name` of their current mentor there (null when they have none).
     *
     * @return Builder<ProgramParticipant>
     */
    public function memberships(User $viewer, Program $program): Builder
    {
        $memberships = ProgramParticipant::query();

        return $memberships
            ->select(['id', 'program_id', 'user_id'])
            ->addSelect(['mentor_name' => MentorAssignment::query()->currentMentorNameOfRow($memberships, 'program_participants.program_id')])
            ->where('program_id', $program->id)
            ->whereHas('participant', fn (Builder $participants): Builder => $participants->where('role', UserRole::Participant))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->with('participant:id,name,email,employee_id')
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'program_participants.user_id'))
            ->orderBy('user_id');
    }

    /**
     * The sessions the participants attended on the curriculum's active weeks, keyed by participant id and then week
     * id. Records on inactive weeks are left out. One query.
     *
     * @param  list<int>  $participantIds
     * @return array<int, array<int, true>>
     */
    public function attendedPairs(Program $program, array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $pairs = [];

        $records = WeeklySessionAttendance::query()
            ->whereIn('user_id', $participantIds)
            ->whereHas('week', fn (Builder $weeks): Builder => $weeks->where('program_id', $program->id)->where('is_active', true))
            ->toBase()
            ->get(['user_id', 'training_week_id']);

        foreach ($records as $record) {
            $pairs[(int) $record->user_id][(int) $record->training_week_id] = true;
        }

        return $pairs;
    }
}
