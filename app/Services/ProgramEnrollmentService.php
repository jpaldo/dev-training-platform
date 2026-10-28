<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who is in a curriculum: participants join it once (themselves, by ticking it when they register, or added by an
 * admin) and are in all of its weeks; removing one keeps their work.
 */
class ProgramEnrollmentService
{
    public function __construct(
        private AdminAuditService $audit,
        private MentorAssignmentService $mentorAssignments,
    ) {}

    /**
     * Add the participants to the curriculum, joined now and added by the admin, and record
     * `program_participant_added` for each of them. Returns the number added.
     *
     * @param  list<int>  $participantIds
     */
    public function addParticipants(Program $program, array $participantIds, User $admin): int
    {
        return DB::transaction(function () use ($program, $participantIds, $admin): int {
            $participants = User::query()->whereKey($participantIds)->get();
            $membership = ['joined_at' => now(), 'added_by' => $admin->id];

            $program->participants()->attach($participants->mapWithKeys(fn (User $participant): array => [$participant->id => $membership])->all());

            $participants->each(fn (User $participant): AdminAuditLog => $this->audit->record($admin, 'program_participant_added', $participant, [
                'program_id' => $program->id,
            ]));

            return $participants->count();
        });
    }

    /**
     * Remove the participant from the curriculum, end their current mentor assignment there and record
     * `program_participant_removed`. Only the membership row is deleted: their work stays with its activity, Gate or
     * week and their mentor history with the curriculum, so adding them again restores access to the work (without a
     * mentor until one is assigned).
     */
    public function removeParticipant(Program $program, User $participant, User $admin): void
    {
        DB::transaction(function () use ($program, $participant, $admin): void {
            $this->mentorAssignments->endCurrentAssignment($program, $participant);

            $program->participants()->detach($participant->id);

            $this->audit->record($admin, 'program_participant_removed', $participant, [
                'program_id' => $program->id,
            ]);
        });
    }

    /**
     * Add the participant to the curriculum as joining themselves: joined now, with no admin in `added_by` and no audit
     * row (it is not an admin action). Idempotent: a participant already in the curriculum keeps their membership as it
     * is.
     */
    public function join(Program $program, User $participant): ProgramParticipant
    {
        return ProgramParticipant::query()->firstOrCreate(
            ['program_id' => $program->id, 'user_id' => $participant->id],
            ['joined_at' => now(), 'added_by' => null],
        );
    }

    /**
     * Save the GitHub repository the participant uses for the curriculum (replacing an earlier one). They must be in
     * the curriculum; the request has already checked that and validated the link (GitHubRepositoryUrl).
     */
    public function saveRepositoryUrl(Program $program, User $participant, string $repositoryUrl): void
    {
        ProgramParticipant::query()
            ->forParticipantInProgram($program->id, $participant->id)
            ->firstOrFail()
            ->update(['repository_url' => $repositoryUrl]);
    }

    /**
     * The curricula a new participant can tick on the registration form: those open to join, the earliest first week
     * first and unscheduled ones last (the order of the landing page's Available curricula), each with its name and
     * dates. `value` is the program ID as the form posts it.
     *
     * @return list<array{value: string, title: string, date_range: string}>
     */
    public function registrationChoices(): array
    {
        return Program::query()
            ->select(['id', 'name'])
            ->openToJoin()
            ->withScheduleBounds()
            ->get()
            ->sortBy([
                fn (Program $first, Program $second): int => ($first->schedule_starts_on === null) <=> ($second->schedule_starts_on === null),
                fn (Program $first, Program $second): int => (string) $first->schedule_starts_on <=> (string) $second->schedule_starts_on,
                fn (Program $first, Program $second): int => $first->id <=> $second->id,
            ])
            ->map(fn (Program $program): array => [
                'value' => (string) $program->id,
                'title' => $program->name,
                'date_range' => $program->dateRangeLabel(),
            ])
            ->values()
            ->all();
    }
}
