<?php

namespace Tests\Feature;

use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParticipantNotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_participant_queues_their_first_sync(): void
    {
        Queue::fake();

        User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();

        $this->assertQueuedOneSyncFor($participant);
    }

    public function test_self_registration_queues_the_new_participants_first_sync(): void
    {
        Queue::fake();

        $this->post(route('register.store'), [
            'employee_id' => 'FS-3001',
            'first_name' => 'Registered',
            'last_name' => 'Participant',
            'email' => 'registered@example.test',
            'job_role' => 'developer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('profile.show'));

        $this->assertQueuedOneSyncFor(User::where('email', 'registered@example.test')->sole());
    }

    public function test_registering_with_a_curriculum_ticked_raises_that_curriculums_notifications(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $program = $week->program;

        $this->post(route('register.store'), [
            'employee_id' => 'FS-3002',
            'first_name' => 'Joining',
            'last_name' => 'Participant',
            'email' => 'joining@example.test',
            'job_role' => 'developer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'program_ids' => [(string) $program->id],
        ])->assertRedirect(route('profile.show'));

        $participant = User::where('email', 'joining@example.test')->sole();
        $this->assertTrue($participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:incomplete")->exists());
    }

    public function test_joining_a_curriculum_queues_a_sync(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): mixed => $this->actingAs($participant)
            ->post(route('training.programs.join', $program))
            ->assertRedirect(route('training.programs.show', $program)));
    }

    public function test_an_admin_adding_participants_queues_a_sync_for_each(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $participants = User::factory()->count(2)->create();
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.programs.participants.store', $program), ['user_ids' => $participants->map(fn (User $participant): string => (string) $participant->id)->all()])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateParticipantNotificationsJob::class, 2);
        foreach ($participants as $participant) {
            Queue::assertPushed(GenerateParticipantNotificationsJob::class, fn (GenerateParticipantNotificationsJob $job): bool => $job->participantId === $participant->id);
        }
    }

    public function test_an_admin_removing_a_participant_queues_a_sync(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $program = Program::factory()->withParticipants($participant)->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): mixed => $this->actingAs($admin)
            ->delete(route('admin.programs.participants.destroy', [$program, $participant]))
            ->assertRedirect());
    }

    public function test_promoting_a_user_to_participant_queues_a_sync(): void
    {
        $user = User::factory()->create(['role' => 'coach']);

        $this->assertWriteQueuesOneSyncFor($user, fn (): bool => $user->update(['role' => 'participant']));
    }

    public function test_other_participant_profile_changes_do_not_queue_a_sync(): void
    {
        $participant = User::factory()->create();
        Queue::fake();

        $participant->update(['name' => 'Renamed Participant']);

        Queue::assertNotPushed(GenerateParticipantNotificationsJob::class);
    }

    public function test_changing_a_submission_queues_a_sync(): void
    {
        $participant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): bool => $submission->update(['status' => 'submitted']));
    }

    public function test_creating_a_submission_version_queues_a_sync(): void
    {
        $participant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): SubmissionVersion => SubmissionVersion::factory()->for($submission, 'submission')->create());
    }

    public function test_deleting_evidence_queues_a_sync(): void
    {
        $participant = User::factory()->create();
        $version = SubmissionVersion::factory()->for(ActivitySubmission::factory()->for($participant, 'participant'), 'submission')->create();
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): ?bool => $evidence->delete());
    }

    public function test_uploading_a_weekly_video_queues_a_sync(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): WeeklyVideoRecording => WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create());
    }

    public function test_coach_review_queues_a_sync_for_the_reviewed_participant(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'submitted']);
        MentorAssignment::factory()->create(['program_id' => $submission->activity->week->program_id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);

        $this->assertWriteQueuesOneSyncFor($participant, fn (): mixed => $this->actingAs($coach)
            ->post(route('coach.reviews.store', $version), ['decision' => 'needs_revision', 'comments' => 'Please clarify the evidence.'])
            ->assertRedirect());
        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'status' => 'needs_revision']);
    }

    public function test_coach_gate_evaluation_queues_a_sync_for_the_evaluated_participant(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criterion = GateCriterion::factory()->for($gate, 'gate')->create();
        $program = $week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $program->mentors()->attach($coach);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        $this->assertWriteQueuesOneSyncFor($participant, fn (): mixed => $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$program, $week, $participant, $gate]), ['status' => 'needs_revision', 'criteria' => [$criterion->id => 'not_met']])
            ->assertRedirect());
        $this->assertDatabaseHas('gate_evaluations', ['training_gate_id' => $gate->id, 'user_id' => $participant->id, 'status' => 'needs_revision']);
    }

    public function test_several_writes_for_one_participant_queue_one_sync_until_it_runs(): void
    {
        $participant = User::factory()->create();

        $this->assertWriteQueuesOneSyncFor($participant, function () use ($participant): void {
            ActivitySubmission::factory()->count(3)->for($participant, 'participant')->create();
        });
    }

    /**
     * Fake the queue, run the write, and assert it queued exactly one notification sync, for the given participant.
     */
    private function assertWriteQueuesOneSyncFor(User $participant, Closure $write): void
    {
        Queue::fake();

        $write();

        $this->assertQueuedOneSyncFor($participant);
    }

    private function assertQueuedOneSyncFor(User $participant): void
    {
        Queue::assertPushed(GenerateParticipantNotificationsJob::class, 1);
        Queue::assertPushed(GenerateParticipantNotificationsJob::class, fn (GenerateParticipantNotificationsJob $job): bool => $job->participantId === $participant->id);
    }
}
