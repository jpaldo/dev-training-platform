<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Http\Requests\AssignMentorRequest;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\User;
use App\Support\ProgramRoster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ProgramPeopleTabService
{
    /**
     * The curriculum's people, read once for the Participants and Mentors tabs (tabData()) and the overview counts
     * (`ProgramOverviewTabService::tabData()`): the participants by name in one query with their membership, their
     * mentor assignments in the curriculum, current and ended, in one query with their mentors (two queries), and the
     * mentors by name in one query with their number of current participants in the curriculum.
     */
    public function roster(Program $program): ProgramRoster
    {
        $participants = $program->participants()
            ->select(['users.id', 'users.name', 'users.employee_id', 'users.job_role'])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();

        $assignmentsByParticipant = $program->mentorAssignments()
            ->select(['id', 'program_id', 'participant_id', 'mentor_id', 'started_at', 'ended_at'])
            ->whereIn('participant_id', $participants->modelKeys())
            ->with('mentor:id,name')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->groupBy('participant_id');

        $mentors = $program->mentors()
            ->select(['users.id', 'users.name', 'users.employee_id'])
            ->withCount(['currentMentorAssignments as current_participant_count' => fn (Builder $assignments): Builder => $assignments
                ->where('program_id', $program->id)])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();

        return new ProgramRoster($participants, $assignmentsByParticipant, $mentors);
    }

    /**
     * The content of the curriculum page's Participants and Mentors tabs. Each participant has their current mentor, the
     * form that assigns or changes it (a select of the curriculum's mentors, `mentorOptions`), their mentor history and
     * a Remove link; the add form offers the active participants not in the curriculum (one query). Each mentor has
     * their number of current participants and a Remove link whose confirmation warns that those participants become
     * unassigned; the add form offers the active coaches who are not mentors of the curriculum (one more query).
     *
     * @return array{participants: list<array{id: int, name: string, employee_id: string, job_role: string, current_mentor: string, current_mentor_id: string, joined_on: string, assign_url: string, error_bag: string, history: list<string>, remove_url: string}>, addParticipants: array{url: string, size: int, options: list<array{value: string, label: string}>}, mentors: list<array{id: int, name: string, employee_id: string, current_participant_count: int, remove_url: string, remove_confirmation: string}>, addMentors: array{url: string, size: int, options: list<array{value: string, label: string}>}, mentorOptions: list<array{value: string, label: string}>}
     */
    public function tabData(Program $program, ProgramRoster $roster): array
    {
        $mentors = $this->mentorRows($program, $roster);

        return [
            'participants' => $this->participantRows($program, $roster),
            'addParticipants' => $this->addParticipantsForm($program),
            'mentors' => $mentors,
            'addMentors' => $this->addMentorsForm($program),
            'mentorOptions' => array_map(fn (array $mentor): array => ['value' => (string) $mentor['id'], 'label' => $mentor['name']], $mentors),
        ];
    }

    /**
     * One row per participant. A missing employee ID or job role shows as a dash, and a participant without a current
     * mentor as "Not assigned" with an empty `current_mentor_id`. The row carries the form that assigns or changes their
     * mentor and their mentor history, the earliest first, e.g. ["Ana Reyes, 9 Nov – 23 Nov", "Ben Lim, since 23 Nov"].
     *
     * @return list<array{id: int, name: string, employee_id: string, job_role: string, current_mentor: string, current_mentor_id: string, joined_on: string, assign_url: string, error_bag: string, history: list<string>, remove_url: string}>
     */
    private function participantRows(Program $program, ProgramRoster $roster): array
    {
        return $roster->participants
            ->map(function (User $participant) use ($program, $roster): array {
                $currentAssignment = $roster->currentAssignment($participant);

                return [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'employee_id' => $participant->employee_id ?? '—',
                    'job_role' => $participant->job_role?->label() ?? '—',
                    'current_mentor' => $currentAssignment?->mentor->name ?? 'Not assigned',
                    'current_mentor_id' => (string) $currentAssignment?->mentor_id,
                    'joined_on' => $participant->pivot->joined_at->format('j M Y'),
                    'assign_url' => route('admin.programs.assignments.update', [$program, $participant]),
                    'error_bag' => AssignMentorRequest::errorBagFor($participant),
                    'history' => $roster->assignmentsOf($participant)
                        ->map(fn (MentorAssignment $assignment): string => $assignment->ended_at === null
                            ? "{$assignment->mentor->name}, since {$assignment->started_at->format('j M')}"
                            : "{$assignment->mentor->name}, {$assignment->started_at->format('j M')} – {$assignment->ended_at->format('j M')}")
                        ->values()
                        ->all(),
                    'remove_url' => route('admin.programs.participants.destroy', [$program, $participant]),
                ];
            })
            ->all();
    }

    /**
     * The add participants form: every active participant who is not in the curriculum yet, by name, listed as
     * "Name (Employee ID)".
     *
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addParticipantsForm(Program $program): array
    {
        $candidates = User::query()
            ->select(['id', 'name', 'employee_id'])
            ->where('role', UserRole::Participant)
            ->where('is_active', true)
            ->whereDoesntHave('programs', fn (Builder $programs): Builder => $programs->whereKey($program->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->addUsersForm(route('admin.programs.participants.store', $program), $candidates);
    }

    /**
     * One row per mentor of the curriculum, by name. A missing employee ID shows as a dash.
     *
     * @return list<array{id: int, name: string, employee_id: string, current_participant_count: int, remove_url: string, remove_confirmation: string}>
     */
    private function mentorRows(Program $program, ProgramRoster $roster): array
    {
        return $roster->mentors
            ->map(fn (User $mentor): array => [
                'id' => $mentor->id,
                'name' => $mentor->name,
                'employee_id' => $mentor->employee_id ?? '—',
                'current_participant_count' => (int) $mentor->current_participant_count,
                'remove_url' => route('admin.programs.mentors.destroy', [$program, $mentor]),
                'remove_confirmation' => $this->removeMentorConfirmation($mentor->name, (int) $mentor->current_participant_count),
            ])
            ->all();
    }

    /**
     * The question asked before a mentor is removed, warning that their current participants become unassigned.
     */
    private function removeMentorConfirmation(string $mentorName, int $currentParticipantCount): string
    {
        $question = "Remove {$mentorName} as a mentor of this curriculum?";

        if ($currentParticipantCount === 0) {
            return "{$question} They have no participants assigned.";
        }

        return "{$question} Their {$currentParticipantCount} ".Str::plural('participant', $currentParticipantCount).' will become unassigned.';
    }

    /**
     * The add mentors form: every active coach who is not a mentor of the curriculum yet, by name, listed as
     * "Name (Employee ID)".
     *
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addMentorsForm(Program $program): array
    {
        $candidates = User::query()
            ->select(['id', 'name', 'employee_id'])
            ->where('role', UserRole::Coach)
            ->where('is_active', true)
            ->whereDoesntHave('mentoredPrograms', fn (Builder $programs): Builder => $programs->whereKey($program->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->addUsersForm(route('admin.programs.mentors.store', $program), $candidates);
    }

    /**
     * A form that adds users to the curriculum from a multi-select listing each candidate as "Name (Employee ID)",
     * three to eight rows tall.
     *
     * @param  Collection<int, User>  $candidates
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addUsersForm(string $url, Collection $candidates): array
    {
        return [
            'url' => $url,
            'size' => max(3, min(8, $candidates->count())),
            'options' => $candidates
                ->map(fn (User $candidate): array => [
                    'value' => (string) $candidate->id,
                    'label' => $candidate->name.' ('.($candidate->employee_id ?? 'no employee ID').')',
                ])
                ->all(),
        ];
    }
}
