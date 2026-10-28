<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklySessionAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        $memberships = $this->visibleMemberships($viewer, $program);

        return $memberships
            ->select(['id', 'program_id', 'user_id'])
            ->addSelect(['mentor_name' => MentorAssignment::query()->currentMentorNameOfRow($memberships, 'program_participants.program_id')])
            ->with('participant:id,name,email,employee_id')
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'program_participants.user_id'))
            ->orderBy('user_id');
    }

    /**
     * The ids of the participants the viewer sees in the curriculum (the users of memberships()). One query.
     *
     * @return list<int>
     */
    public function visibleParticipantIds(User $viewer, Program $program): array
    {
        return array_map(intval(...), $this->visibleMemberships($viewer, $program)->pluck('user_id')->all());
    }

    /**
     * The ids of the curriculum's active weeks, whatever their dates. One query.
     *
     * @return list<int>
     */
    public function activeWeekIds(Program $program): array
    {
        return array_map(intval(...), $program->activeWeeks()->pluck('id')->all());
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

    /**
     * How many of the curriculum's active weeks held a session for the participant: the weeks that have started
     * (TrainingWeek::isOpen()) plus the attended ones that have not, so the rate never exceeds 100%. Reads the loaded
     * active weeks.
     *
     * @param  array<int, true>  $attendedWeeks  the participant's attended active weeks, keyed by week id (attendedPairs())
     */
    public function heldCount(Program $program, array $attendedWeeks): int
    {
        return $program->activeWeeks
            ->filter(fn (TrainingWeek $week): bool => $week->isOpen() || isset($attendedWeeks[$week->id]))
            ->count();
    }

    /**
     * The attendance rate in whole percent, rounded half up, or null when no session was held.
     */
    public function rate(int $attended, int $held): ?int
    {
        return $held === 0 ? null : (int) round($attended * 100 / $held);
    }

    /**
     * The CSV export of the curriculum's weekly session attendance: the header row, then one row per participant the
     * viewer sees (memberships() order) with their mentor ("Not assigned" without one), a cell per active week
     * ("Attended" when ticked, "Absent" when the week has started, otherwise "Not held"), the attended and held counts
     * and the rate (empty when nothing was held). Only the header row without a curriculum. At most four queries whatever
     * the number of participants: the curriculum's active weeks (unless loaded), the memberships, their participants
     * and the attended sessions.
     *
     * @return list<list<string>>
     */
    public function csvRows(User $viewer, ?Program $program): array
    {
        $weeks = $program === null ? new Collection : $program->activeWeeks;
        $header = [
            'Participant',
            'Employee ID',
            'Email',
            'Mentor',
            ...$weeks->map(fn (TrainingWeek $week): string => "Week {$week->week_number}")->all(),
            'Attended',
            'Held',
            'Rate (%)',
        ];

        if ($program === null) {
            return [$header];
        }

        $memberships = $this->memberships($viewer, $program)->get();
        $attended = $this->attendedPairs($program, $memberships->pluck('user_id')->all());

        return [
            $header,
            ...$memberships->map(function (ProgramParticipant $membership) use ($program, $weeks, $attended): array {
                $participant = $membership->participant;
                $attendedWeeks = $attended[$participant->id] ?? [];
                $held = $this->heldCount($program, $attendedWeeks);

                return [
                    $participant->name,
                    $participant->employee_id ?? '',
                    $participant->email,
                    $membership->mentor_name ?? 'Not assigned',
                    ...$weeks->map(fn (TrainingWeek $week): string => match (true) {
                        isset($attendedWeeks[$week->id]) => 'Attended',
                        $week->isOpen() => 'Absent',
                        default => 'Not held',
                    })->all(),
                    (string) count($attendedWeeks),
                    (string) $held,
                    (string) $this->rate(count($attendedWeeks), $held),
                ];
            })->all(),
        ];
    }

    /**
     * Save the posted cells as shown, in one transaction: a cell posted 1 gets a record marked by the viewer now unless
     * it has one (an existing record keeps its first marker and time, so saving twice changes nothing), and a cell
     * posted 0 loses its record. The cells must already be in the viewer's scope (UpdateSessionAttendanceRequest).
     * Queries: one read, one delete per week with unticked records, and one insert, whatever the number of
     * participants.
     *
     * @param  array<int, array<int, bool>>  $cells  keyed by participant id and then week id, true when attended
     */
    public function save(User $viewer, array $cells): void
    {
        $weekIds = collect($cells)->flatMap(fn (array $weeks): array => array_keys($weeks))->unique()->values()->all();

        if ($weekIds === []) {
            return;
        }

        DB::transaction(function () use ($viewer, $cells, $weekIds): void {
            $existing = [];
            $records = WeeklySessionAttendance::query()
                ->whereIn('user_id', array_keys($cells))
                ->whereIn('training_week_id', $weekIds)
                ->toBase()
                ->get(['user_id', 'training_week_id']);

            foreach ($records as $record) {
                $existing[(int) $record->user_id][(int) $record->training_week_id] = true;
            }

            $now = now();
            $absentParticipantIdsByWeek = [];
            $newRecords = [];

            foreach ($cells as $participantId => $weeks) {
                foreach ($weeks as $weekId => $isAttended) {
                    $hasRecord = isset($existing[$participantId][$weekId]);

                    if ($isAttended && ! $hasRecord) {
                        $newRecords[] = [
                            'training_week_id' => $weekId,
                            'user_id' => $participantId,
                            'marked_by' => $viewer->id,
                            'marked_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    } elseif (! $isAttended && $hasRecord) {
                        $absentParticipantIdsByWeek[$weekId][] = $participantId;
                    }
                }
            }

            foreach ($absentParticipantIdsByWeek as $weekId => $participantIds) {
                WeeklySessionAttendance::query()->where('training_week_id', $weekId)->whereIn('user_id', $participantIds)->delete();
            }

            if ($newRecords !== []) {
                WeeklySessionAttendance::query()->insertOrIgnore($newRecords);
            }
        });
    }

    /**
     * The curriculum's memberships the viewer sees, unordered and without columns chosen: users with the Participant role,
     * all of them for an admin, otherwise only those whose current mentor in the curriculum is the viewer.
     *
     * @return Builder<ProgramParticipant>
     */
    private function visibleMemberships(User $viewer, Program $program): Builder
    {
        return ProgramParticipant::query()
            ->where('program_id', $program->id)
            ->whereHas('participant', fn (Builder $participants): Builder => $participants->where('role', UserRole::Participant))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer));
    }
}
