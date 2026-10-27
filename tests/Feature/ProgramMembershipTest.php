<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\ProgramResource;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProgramMembershipTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participants_and_their_curricula_share_the_membership_with_its_join_details(): void
    {
        $this->travelTo('2026-11-09 08:00:00');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $participant = User::factory()->create();
        $program = Program::factory()->create();

        $this->assertFalse($program->hasParticipants());
        $program->participants()->attach($participant, ['joined_at' => now(), 'added_by' => $admin->id]);

        $membership = $participant->programs()->sole()->pivot;
        $this->assertInstanceOf(ProgramParticipant::class, $membership);
        $this->assertTrue($membership->joined_at->equalTo(now()));
        $this->assertTrue($membership->addedBy->is($admin));
        $this->assertTrue($program->participants()->sole()->is($participant));
        $this->assertTrue($program->hasParticipants());
        $this->assertTrue(ProgramParticipant::query()->forParticipantInProgram($program->id, $participant->id)->sole()->program->is($program));
    }

    public function test_includes_only_its_own_participants_and_mentors(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withParticipants($participant)->withMentors($coach)->create();
        $otherProgram = Program::factory()->create();

        $this->assertTrue($program->includesParticipant($participant));
        $this->assertTrue($program->includesMentor($coach));
        $this->assertFalse($program->includesParticipant($coach));
        $this->assertFalse($program->includesMentor($participant));
        $this->assertFalse($otherProgram->includesParticipant($participant));
        $this->assertFalse($otherProgram->includesMentor($coach));
        $this->assertTrue($coach->mentoredPrograms()->sole()->is($program));
    }

    public function test_active_weeks_are_the_programs_active_weeks_in_week_order_and_the_weeks_it_includes(): void
    {
        $program = Program::factory()->withWeeks(3)->create();
        $inactiveWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 4, 'position' => 4, 'is_active' => false]);
        $otherProgramWeek = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);

        $this->assertSame([1, 2, 3], $program->activeWeeks->pluck('week_number')->all());
        $this->assertTrue($program->includesWeek($program->activeWeeks->last()));
        $this->assertFalse($program->includesWeek($inactiveWeek));
        $this->assertFalse($program->includesWeek($otherProgramWeek));
    }

    public function test_resources_and_mentor_assignments_belong_to_the_curriculum(): void
    {
        $program = Program::factory()->create();
        $driveFile = ProgramResource::factory()->for($program)->googleDrive()->forWeek(2)->create();
        $link = ProgramResource::factory()->for($program)->create(['url' => 'https://example.com/guide']);
        ProgramResource::factory()->create();
        $assignment = MentorAssignment::factory()->for($program)->create();

        $this->assertEqualsCanonicalizing([$driveFile->id, $link->id], $program->resources()->pluck('id')->all());
        $this->assertSame(2, $driveFile->week_number);
        $this->assertNotNull($driveFile->googleDriveLink());
        $this->assertNull($link->googleDriveLink());
        $this->assertTrue($program->mentorAssignments()->sole()->is($assignment));
        $this->assertTrue($assignment->program->is($program));
    }

    public function test_only_an_admin_or_the_current_mentor_in_that_curriculum_mentors_the_participant(): void
    {
        $participant = User::factory()->create();
        $currentMentor = User::factory()->create(['role' => UserRole::Coach]);
        $previousMentor = User::factory()->create(['role' => UserRole::Coach]);
        $otherProgramMentor = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withParticipants($participant)->create();
        $otherProgram = Program::factory()->withParticipants($participant)->create();
        $this->assignMentor($program, $participant, $previousMentor, ended: true);
        $this->assignMentor($program, $participant, $currentMentor);
        $this->assignMentor($otherProgram, $participant, $otherProgramMentor);

        $this->assertTrue(User::factory()->create(['role' => UserRole::Admin])->mentorsParticipantInProgram($participant, $program));
        $this->assertTrue($currentMentor->mentorsParticipantInProgram($participant, $program));
        $this->assertFalse($previousMentor->mentorsParticipantInProgram($participant, $program));
        $this->assertFalse($otherProgramMentor->mentorsParticipantInProgram($participant, $program));
        $this->assertFalse($participant->mentorsParticipantInProgram($participant, $program));
    }

    public function test_currently_mentored_by_keeps_the_memberships_the_coach_currently_mentors_in_their_curriculum(): void
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $mentored = User::factory()->create();
        $formerlyMentored = User::factory()->create();
        $mentoredElsewhere = User::factory()->create();
        $program = Program::factory()->withParticipants($mentored, $formerlyMentored, $mentoredElsewhere)->create();
        $otherProgram = Program::factory()->withParticipants($mentored, $mentoredElsewhere)->create();
        $this->assignMentor($program, $mentored, $coach);
        $this->assignMentor($program, $formerlyMentored, $coach, ended: true);
        $this->assignMentor($otherProgram, $mentoredElsewhere, $coach);

        $memberships = ProgramParticipant::query()
            ->where('program_id', $program->id)
            ->currentlyMentoredBy($coach)
            ->pluck('user_id')
            ->all();

        $this->assertSame([$mentored->id], $memberships);
    }

    /**
     * Assign the coach as the participant's mentor in the curriculum, current unless ended.
     */
    private function assignMentor(Program $program, User $participant, User $mentor, bool $ended = false): MentorAssignment
    {
        $factory = MentorAssignment::factory()->for($program);

        return ($ended ? $factory->ended() : $factory)->create([
            'participant_id' => $participant->id,
            'mentor_id' => $mentor->id,
        ]);
    }
}
