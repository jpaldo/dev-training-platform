<?php

namespace App\Support;

use App\Models\MentorAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The people of one curriculum as its admin page reads them once and shares between its tabs: the participants by name
 * with their membership as the pivot, their mentor assignments in the curriculum (current and ended, the earliest
 * first, each with its mentor's name, keyed by participant ID) and the mentors by name with their number of current
 * participants in the curriculum (`current_participant_count`). Built by `ProgramPeopleTabService::roster()`.
 */
class ProgramRoster
{
    /**
     * @param  Collection<int, User>  $participants
     * @param  SupportCollection<int, Collection<int, MentorAssignment>>  $assignmentsByParticipant
     * @param  Collection<int, User>  $mentors
     */
    public function __construct(
        public readonly Collection $participants,
        public readonly SupportCollection $assignmentsByParticipant,
        public readonly Collection $mentors,
    ) {}

    /**
     * The participant's mentor assignments in the curriculum, current and ended, the earliest first.
     *
     * @return Collection<int, MentorAssignment>
     */
    public function assignmentsOf(User $participant): Collection
    {
        return $this->assignmentsByParticipant->get($participant->id, new Collection);
    }

    /**
     * The participant's current assignment, or null when they have no mentor.
     */
    public function currentAssignment(User $participant): ?MentorAssignment
    {
        return $this->assignmentsOf($participant)
            ->first(fn (MentorAssignment $assignment): bool => $assignment->ended_at === null);
    }
}
