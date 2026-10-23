<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Jobs\SyncTrainingDataJob;
use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivityRequirement;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GoogleDriveConnection;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SessionSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_keeps_a_separate_draft_of_the_same_activity_in_each_session(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();

        $this->actingAs($participant)->post(route('training.submission.draft', [$sessionA, $week, $activity]), ['field_values' => ['finding' => 'Found in session A']])->assertRedirect();
        $this->actingAs($participant)->post(route('training.submission.draft', [$sessionB, $week, $activity]), ['field_values' => ['finding' => 'Found in session B']])->assertRedirect();

        $this->assertSame(['finding' => 'Found in session A'], $sessionA->activitySubmissions()->sole()->versions()->sole()->field_values);
        $this->assertSame(['finding' => 'Found in session B'], $sessionB->activitySubmissions()->sole()->versions()->sole()->field_values);
        $this->actingAs($participant)->get(route('training.submission.edit', [$sessionA, $week, $activity]))
            ->assertOk()
            ->assertSee('Found in session A')
            ->assertDontSee('Found in session B');
        $this->actingAs($participant)->get(route('training.submission.edit', [$sessionB, $week, $activity]))
            ->assertOk()
            ->assertSee('Found in session B')
            ->assertDontSee('Found in session A');
    }

    public function test_attempts_and_scores_of_one_session_do_not_show_in_the_other(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();
        $version = $this->version($participant, $sessionA, $activity, SubmissionStatus::Approved);
        ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => User::factory()->create(['role' => 'coach'])->id, 'status' => 'published', 'total_score' => 88, 'performance_band' => 'Proficient']);

        $this->actingAs($participant)->get(route('training.activities.show', [$sessionA, $week, $activity]))
            ->assertOk()
            ->assertSee('88 / 100');
        $this->actingAs($participant)->get(route('training.activities.show', [$sessionB, $week, $activity]))
            ->assertOk()
            ->assertSee('Start submission')
            ->assertDontSee('88 / 100')
            ->assertDontSee(route('training.submission.show', [$sessionA, $week, $activity, $version]), false);
    }

    public function test_a_version_of_an_attempt_in_another_session_is_not_found(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();
        $submitted = $this->version($participant, $sessionA, $activity, SubmissionStatus::Submitted);
        $draft = $this->version($participant, $sessionA, $activity, SubmissionStatus::Draft);
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'text']);

        $this->actingAs($participant)->get(route('training.submission.show', [$sessionA, $week, $activity, $submitted]))->assertOk();
        $this->actingAs($participant)->get(route('training.submission.show', [$sessionB, $week, $activity, $submitted]))->assertNotFound();
        $this->actingAs($participant)->post(route('training.submission.submit', [$sessionB, $week, $activity, $draft]))->assertNotFound();
        $this->actingAs($participant)->post(route('training.evidence.store', [$sessionB, $week, $activity, $draft]), ['evidence_requirement_id' => $requirement->id, 'content' => 'Wrong session'])->assertNotFound();

        $this->assertSame(SubmissionStatus::Draft, $draft->fresh()->status);
        $this->assertDatabaseCount('submission_evidence', 0);
    }

    public function test_a_revision_draft_is_only_made_from_an_attempt_in_the_same_session(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();
        $this->version($participant, $sessionA, $activity, SubmissionStatus::Submitted, SubmissionStatus::NeedsRevision);

        $this->actingAs($participant)->post(route('training.submission.revise', [$sessionB, $week, $activity]))->assertNotFound();
        $this->actingAs($participant)->post(route('training.submission.revise', [$sessionA, $week, $activity]))->assertRedirect();

        $this->assertSame(2, $sessionA->activitySubmissions()->sole()->versions()->count());
        $this->assertSame(0, $sessionB->activitySubmissions()->count());
    }

    public function test_a_coach_retry_opens_the_next_attempt_in_the_failed_attempts_session(): void
    {
        ['participant' => $participant, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();
        ActivitySubmission::factory()->for($sessionA)->for($activity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Failed, 'attempt_number' => 1]);
        ActivitySubmission::factory()->for($sessionA)->for($activity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Draft, 'attempt_number' => 2]);
        $failed = $this->version($participant, $sessionB, $activity, SubmissionStatus::Submitted, SubmissionStatus::Failed);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('coach.reviews.retry', $failed))
            ->assertSessionHas('status', 'Retry attempt 2 created.');

        $this->assertDatabaseHas('activity_submissions', ['training_session_id' => $sessionB->id, 'user_id' => $participant->id, 'activity_id' => $activity->id, 'attempt_number' => 2, 'status' => 'draft']);
        $this->assertSame(2, $sessionA->activitySubmissions()->count());
    }

    public function test_an_approved_recovery_request_opens_the_next_attempt_in_the_failed_attempts_session(): void
    {
        ['participant' => $participant, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();
        ActivitySubmission::factory()->for($sessionA)->for($activity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Failed, 'attempt_number' => 1]);
        ActivitySubmission::factory()->for($sessionA)->for($activity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Failed, 'attempt_number' => 2]);
        $failed = ActivitySubmission::factory()->for($sessionB)->for($activity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Failed, 'attempt_number' => 1]);
        $recoveryRequest = ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'requested_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('coach.recovery.decide', $recoveryRequest), ['decision' => 'approved'])
            ->assertSessionHas('status', 'Recovery request updated.');

        $retry = $sessionB->activitySubmissions()->where('attempt_number', 2)->sole();
        $this->assertSame(SubmissionStatus::Draft, $retry->status);
        $this->assertSame(1, $retry->versions()->count());
        $this->assertSame(2, $sessionA->activitySubmissions()->count());
    }

    public function test_the_activity_export_sends_only_the_work_of_the_session(): void
    {
        Queue::fake();
        config(['services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/callback']);
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'sessionA' => $sessionA, 'sessionB' => $sessionB] = $this->scene();
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'folder']);
        $participant->update(['drive_link' => 'https://drive.google.com/drive/folders/folder']);
        $participant->refresh();
        $this->version($participant, $sessionA, $activity, SubmissionStatus::Draft);

        $this->actingAs($participant)->post(route('training.activities.export', [$sessionB, $week, $activity]))
            ->assertSessionHasErrors(['google_drive' => 'Save activity work before exporting it to Google Drive.']);
        Queue::assertNotPushed(SyncTrainingDataJob::class);

        $this->actingAs($participant)->post(route('training.activities.export', [$sessionA, $week, $activity]))
            ->assertSessionHas('status', 'Activity export queued.');
        Queue::assertPushed(SyncTrainingDataJob::class);
    }

    public function test_removing_the_participant_from_a_session_keeps_their_submissions_there(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'sessionA' => $sessionA] = $this->scene();
        $submission = $this->version($participant, $sessionA, $activity, SubmissionStatus::Submitted)->submission;

        app(TrainingSessionService::class)->removeParticipant($sessionA, $participant, User::factory()->create(['role' => 'admin']));

        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'training_session_id' => $sessionA->id, 'user_id' => $participant->id]);
        $this->assertDatabaseHas('submission_versions', ['activity_submission_id' => $submission->id]);
        $this->actingAs($participant)->get(route('training.submission.edit', [$sessionA, $week, $activity]))->assertForbidden();
    }

    /**
     * A checked-in participant in two sessions of the same program, and a Week 1 activity with a "Finding" field.
     *
     * @return array{participant: User, week: TrainingWeek, activity: Activity, sessionA: TrainingSession, sessionB: TrainingSession}
     */
    private function scene(): array
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        ActivityRequirement::factory()->for($activity)->create([
            'requirement_type' => 'submission_field',
            'position' => 1,
            'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => false],
        ]);
        $sessionA = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $sessionB = TrainingSession::factory()->forWeek($week)->upcoming()->withParticipants($participant)->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return compact('participant', 'week', 'activity', 'sessionA', 'sessionB');
    }

    /**
     * A version with the given status of a new attempt by the participant at the activity in the session.
     */
    private function version(User $participant, TrainingSession $session, Activity $activity, SubmissionStatus $status, ?SubmissionStatus $submissionStatus = null): SubmissionVersion
    {
        $submission = ActivitySubmission::factory()->for($session)->for($activity)->for($participant, 'participant')->create(['status' => $submissionStatus ?? $status]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create([
            'status' => $status,
            'submitted_at' => $status === SubmissionStatus::Draft ? null : now(),
        ]);
    }
}
