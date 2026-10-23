<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\MentorAssignment;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\MentorAssignmentService;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MentorAssignmentServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_add_mentors_makes_the_coaches_session_mentors_and_audits_each(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);

        $added = $this->service()->addMentors($session, [$ana->id, $ben->id], $admin);

        $this->assertSame(2, $added);
        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], $session->mentors()->pluck('users.id')->all());
        $this->assertSame([$session->id], $ana->mentoredSessions()->pluck('training_sessions.id')->all());

        $audits = AdminAuditLog::query()->orderBy('target_user_id')->get();
        $this->assertSame([$ana->id, $ben->id], $audits->pluck('target_user_id')->all());
        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame('session_mentor_added', $audit->action);
            $this->assertSame(['training_session_id' => $session->id], $audit->metadata);
        }
    }

    public function test_assigning_a_mentor_opens_a_current_assignment_and_audits_it(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $mentor = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->withParticipants($participant)->withMentors($mentor)->create();

        $assignment = $this->service()->assign($session, $participant, $mentor, $admin);

        $this->assertSame($mentor->id, $assignment->mentor_id);
        $this->assertSame($admin->id, $assignment->assigned_by);
        $this->assertSame('2026-11-09 10:00:00', $assignment->started_at->toDateTimeString());
        $this->assertNull($assignment->ended_at);
        $this->assertTrue($mentor->is($this->service()->currentMentor($session, $participant)));
        $this->assertSame([$assignment->id], $mentor->currentMentorAssignments()->pluck('id')->all());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('session_mentor_assigned', $audit->action);
        $this->assertSame($participant->id, $audit->target_user_id);
        $this->assertSame([
            'training_session_id' => $session->id,
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
        $session = TrainingSession::factory()->withParticipants($participant)->withMentors($mentorA, $mentorB)->create();
        $first = $this->service()->assign($session, $participant, $mentorA, $admin);

        $this->travelTo('2026-11-23 14:30:00');
        $second = $this->service()->assign($session, $participant, $mentorB, $admin);

        $this->assertSame('2026-11-23 14:30:00', $first->refresh()->ended_at->toDateTimeString());
        $this->assertSame('2026-11-23 14:30:00', $second->started_at->toDateTimeString());
        $this->assertNull($second->ended_at);
        $this->assertTrue($mentorB->is($this->service()->currentMentor($session, $participant)));
        $this->assertSame(0, $mentorA->currentMentorAssignments()->count());

        $history = $this->service()->history($session, $participant);
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
        $session = TrainingSession::factory()->withParticipants($participant)->withMentors($mentor)->create();
        $assignment = $this->service()->assign($session, $participant, $mentor, $admin);

        $this->travel(1)->day();
        $again = $this->service()->assign($session, $participant, $mentor, $admin);

        $this->assertTrue($assignment->is($again));
        $this->assertNull($again->ended_at);
        $this->assertDatabaseCount('mentor_assignments', 1);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    public function test_removing_a_mentor_ends_only_their_current_assignments_in_that_session(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        [$ava, $ben, $cara] = User::factory()->count(3)->create()->all();
        [$removed, $kept] = User::factory()->count(2)->create(['role' => 'coach'])->all();
        $session = TrainingSession::factory()->withParticipants($ava, $ben, $cara)->withMentors($removed, $kept)->create();
        $otherSession = TrainingSession::factory()->withParticipants($ava)->withMentors($removed)->create();
        $avaInSession = $this->service()->assign($session, $ava, $removed, $admin);
        $benInSession = $this->service()->assign($session, $ben, $removed, $admin);
        $caraInSession = $this->service()->assign($session, $cara, $kept, $admin);
        $avaInOtherSession = $this->service()->assign($otherSession, $ava, $removed, $admin);

        $this->travelTo('2026-11-16 09:00:00');
        $this->service()->removeMentor($session, $removed, $admin);

        $this->assertSame('2026-11-16 09:00:00', $avaInSession->refresh()->ended_at->toDateTimeString());
        $this->assertSame('2026-11-16 09:00:00', $benInSession->refresh()->ended_at->toDateTimeString());
        $this->assertNull($caraInSession->refresh()->ended_at);
        $this->assertNull($avaInOtherSession->refresh()->ended_at);
        $this->assertNull($this->service()->currentMentor($session, $ava));
        $this->assertSame([$kept->id], $session->mentors()->pluck('users.id')->all());
        $this->assertSame([$otherSession->id], $removed->mentoredSessions()->pluck('training_sessions.id')->all());

        $audit = AdminAuditLog::query()->latest('id')->first();
        $this->assertSame('session_mentor_removed', $audit->action);
        $this->assertSame($removed->id, $audit->target_user_id);
        $this->assertSame(['training_session_id' => $session->id], $audit->metadata);
    }

    public function test_removing_a_participant_ends_their_current_assignment_and_keeps_the_history(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        [$leaving, $staying] = User::factory()->count(2)->create()->all();
        $mentor = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->withParticipants($leaving, $staying)->withMentors($mentor)->create();
        $leavingAssignment = $this->service()->assign($session, $leaving, $mentor, $admin);
        $stayingAssignment = $this->service()->assign($session, $staying, $mentor, $admin);

        $this->travelTo('2026-11-20 16:00:00');
        app(TrainingSessionService::class)->removeParticipant($session, $leaving, $admin);

        $this->assertFalse($session->includesParticipant($leaving));
        $this->assertSame('2026-11-20 16:00:00', $leavingAssignment->refresh()->ended_at->toDateTimeString());
        $this->assertNull($stayingAssignment->refresh()->ended_at);
        $this->assertNull($this->service()->currentMentor($session, $leaving));
        $this->assertSame([$leavingAssignment->id], $this->service()->history($session, $leaving)->modelKeys());
    }

    public function test_a_mentor_who_is_not_a_session_mentor_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $otherSessionMentor = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->withParticipants($participant)->create();
        TrainingSession::factory()->withParticipants($participant)->withMentors($otherSessionMentor)->create();

        try {
            $this->service()->assign($session, $participant, $otherSessionMentor, $admin);
            $this->fail('Assigning a coach who is not a mentor of the session should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['mentor_id' => ['The selected mentor is not a mentor of this training session.']], $exception->errors());
        }

        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_participant_who_is_not_in_the_session_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $outsider = User::factory()->create();
        $mentor = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->withMentors($mentor)->create();
        TrainingSession::factory()->withParticipants($outsider)->withMentors($mentor)->create();

        try {
            $this->service()->assign($session, $outsider, $mentor, $admin);
            $this->fail('Assigning a mentor to someone outside the session should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['participant' => ['The participant is not in this training session.']], $exception->errors());
        }

        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    private function service(): MentorAssignmentService
    {
        return app(MentorAssignmentService::class);
    }
}
