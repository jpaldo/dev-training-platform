<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\User;
use App\Services\MentorAssignmentService;
use App\Services\ProgramEnrollmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MentorAssignmentServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_add_mentors_makes_the_coaches_curriculum_mentors_and_audits_each(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);

        $added = $this->service()->addMentors($program, [$ana->id, $ben->id], $admin);

        $this->assertSame(2, $added);
        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], $program->mentors()->pluck('users.id')->all());
        $this->assertSame([$program->id], $ana->mentoredPrograms()->pluck('programs.id')->all());

        $audits = AdminAuditLog::query()->orderBy('target_user_id')->get();
        $this->assertSame([$ana->id, $ben->id], $audits->pluck('target_user_id')->all());
        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame('program_mentor_added', $audit->action);
            $this->assertSame(['program_id' => $program->id], $audit->metadata);
        }
    }

    public function test_assigning_a_mentor_opens_a_current_assignment_and_audits_it(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->withParticipants($participant)->withMentors($mentor)->create();

        $assignment = $this->service()->assign($program, $participant, $mentor, $admin);

        $this->assertSame($mentor->id, $assignment->mentor_id);
        $this->assertSame($admin->id, $assignment->assigned_by);
        $this->assertSame('2026-11-09 10:00:00', $assignment->started_at->toDateTimeString());
        $this->assertNull($assignment->ended_at);
        $this->assertTrue($mentor->is($this->service()->currentMentor($program, $participant)));
        $this->assertSame([$assignment->id], $mentor->currentMentorAssignments()->pluck('id')->all());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('program_mentor_assigned', $audit->action);
        $this->assertSame($participant->id, $audit->target_user_id);
        $this->assertSame([
            'program_id' => $program->id,
            'previous_mentor_id' => null,
            'mentor_id' => $mentor->id,
        ], $audit->metadata);
    }

    public function test_changing_the_mentor_ends_the_previous_assignment_at_the_change_time(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        [$mentorA, $mentorB] = User::factory()->count(2)->create(['role' => 'coach'])->all();
        $program = Program::factory()->withParticipants($participant)->withMentors($mentorA, $mentorB)->create();
        $first = $this->service()->assign($program, $participant, $mentorA, $admin);

        $this->travelTo('2026-11-23 14:30:00');
        $second = $this->service()->assign($program, $participant, $mentorB, $admin);

        $this->assertSame('2026-11-23 14:30:00', $first->refresh()->ended_at->toDateTimeString());
        $this->assertSame('2026-11-23 14:30:00', $second->started_at->toDateTimeString());
        $this->assertNull($second->ended_at);
        $this->assertTrue($mentorB->is($this->service()->currentMentor($program, $participant)));
        $this->assertSame(0, $mentorA->currentMentorAssignments()->count());

        $history = $this->service()->history($program, $participant);
        $this->assertSame([$first->id, $second->id], $history->modelKeys());
        $this->assertSame([$mentorA->name, $mentorB->name], $history->map(fn (MentorAssignment $assignment): string => $assignment->mentor->name)->all());

        $this->assertSame(
            ['previous_mentor_id' => $mentorA->id, 'mentor_id' => $mentorB->id],
            collect(AdminAuditLog::query()->latest('id')->first()->metadata)->only(['previous_mentor_id', 'mentor_id'])->all(),
        );
    }

    public function test_assigning_the_current_mentor_again_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->withParticipants($participant)->withMentors($mentor)->create();
        $assignment = $this->service()->assign($program, $participant, $mentor, $admin);

        $this->travel(1)->day();
        $again = $this->service()->assign($program, $participant, $mentor, $admin);

        $this->assertTrue($assignment->is($again));
        $this->assertNull($again->ended_at);
        $this->assertDatabaseCount('mentor_assignments', 1);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    public function test_removing_a_mentor_ends_only_their_current_assignments_in_that_curriculum(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        [$ava, $ben, $cara] = User::factory()->count(3)->create()->all();
        [$removed, $kept] = User::factory()->count(2)->create(['role' => 'coach'])->all();
        $program = Program::factory()->withParticipants($ava, $ben, $cara)->withMentors($removed, $kept)->create();
        $otherProgram = Program::factory()->withParticipants($ava)->withMentors($removed)->create();
        $avaInProgram = $this->service()->assign($program, $ava, $removed, $admin);
        $benInProgram = $this->service()->assign($program, $ben, $removed, $admin);
        $caraInProgram = $this->service()->assign($program, $cara, $kept, $admin);
        $avaInOtherProgram = $this->service()->assign($otherProgram, $ava, $removed, $admin);

        $this->travelTo('2026-11-16 09:00:00');
        $this->service()->removeMentor($program, $removed, $admin);

        $this->assertSame('2026-11-16 09:00:00', $avaInProgram->refresh()->ended_at->toDateTimeString());
        $this->assertSame('2026-11-16 09:00:00', $benInProgram->refresh()->ended_at->toDateTimeString());
        $this->assertNull($caraInProgram->refresh()->ended_at);
        $this->assertNull($avaInOtherProgram->refresh()->ended_at);
        $this->assertNull($this->service()->currentMentor($program, $ava));
        $this->assertSame([$kept->id], $program->mentors()->pluck('users.id')->all());
        $this->assertSame([$otherProgram->id], $removed->mentoredPrograms()->pluck('programs.id')->all());

        $audit = AdminAuditLog::query()->latest('id')->first();
        $this->assertSame('program_mentor_removed', $audit->action);
        $this->assertSame($removed->id, $audit->target_user_id);
        $this->assertSame(['program_id' => $program->id], $audit->metadata);
    }

    public function test_removing_a_participant_ends_their_current_assignment_and_keeps_the_history(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        [$leaving, $staying] = User::factory()->count(2)->create()->all();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->withParticipants($leaving, $staying)->withMentors($mentor)->create();
        $leavingAssignment = $this->service()->assign($program, $leaving, $mentor, $admin);
        $stayingAssignment = $this->service()->assign($program, $staying, $mentor, $admin);

        $this->travelTo('2026-11-20 16:00:00');
        app(ProgramEnrollmentService::class)->removeParticipant($program, $leaving, $admin);

        $this->assertFalse($program->includesParticipant($leaving));
        $this->assertSame('2026-11-20 16:00:00', $leavingAssignment->refresh()->ended_at->toDateTimeString());
        $this->assertNull($stayingAssignment->refresh()->ended_at);
        $this->assertNull($this->service()->currentMentor($program, $leaving));
        $this->assertSame([$leavingAssignment->id], $this->service()->history($program, $leaving)->modelKeys());
    }

    public function test_a_mentor_who_is_not_a_curriculum_mentor_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $otherProgramMentor = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->withParticipants($participant)->create();
        Program::factory()->withParticipants($participant)->withMentors($otherProgramMentor)->create();

        try {
            $this->service()->assign($program, $participant, $otherProgramMentor, $admin);
            $this->fail('Assigning a coach who is not a mentor of the curriculum should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['mentor_id' => ['The selected mentor is not a mentor of this curriculum.']], $exception->errors());
        }

        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_participant_who_is_not_in_the_curriculum_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $outsider = User::factory()->create();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->withMentors($mentor)->create();
        Program::factory()->withParticipants($outsider)->withMentors($mentor)->create();

        try {
            $this->service()->assign($program, $outsider, $mentor, $admin);
            $this->fail('Assigning a mentor to someone outside the curriculum should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['participant' => ['The participant is not in this curriculum.']], $exception->errors());
        }

        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    private function service(): MentorAssignmentService
    {
        return app(MentorAssignmentService::class);
    }
}
