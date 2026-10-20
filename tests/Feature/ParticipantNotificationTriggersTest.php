<?php

namespace Tests\Feature;

use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateCriterion;
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

    public function test_creating_a_daily_log_queues_a_sync(): void
    {
        $participant = User::factory()->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): DailyLog => DailyLog::factory()->for($participant, 'participant')->create());
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

        $this->assertWriteQueuesOneSyncFor($participant, fn (): WeeklyVideoRecording => WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'disk' => 'local', 'path' => 'weekly-video/week.mp4', 'original_filename' => 'week.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 100, 'uploaded_at' => now()]));
    }

    public function test_coach_review_queues_a_sync_for_the_reviewed_participant(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'submitted']);
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
        $coach->assignedParticipants()->attach($participant->id);
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criterion = GateCriterion::factory()->for($gate, 'gate')->create();

        $this->assertWriteQueuesOneSyncFor($participant, fn (): mixed => $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$week, $participant, $gate]), ['status' => 'needs_revision', 'criteria' => [$criterion->id => 'not_met']])
            ->assertRedirect());
        $this->assertDatabaseHas('gate_evaluations', ['training_gate_id' => $gate->id, 'user_id' => $participant->id, 'status' => 'needs_revision']);
    }

    public function test_several_writes_for_one_participant_queue_one_sync_until_it_runs(): void
    {
        $participant = User::factory()->create();

        $this->assertWriteQueuesOneSyncFor($participant, function () use ($participant): void {
            DailyLog::factory()->count(3)->for($participant, 'participant')->create();
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
