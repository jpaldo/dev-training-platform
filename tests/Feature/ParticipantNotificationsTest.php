<?php

namespace Tests\Feature;

use App\Enums\ParticipantNotificationPriority;
use App\Enums\ParticipantNotificationType;
use App\Enums\ReviewDecision;
use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\ParticipantNotificationService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_participant_gets_general_and_week_reminders_without_duplicates(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('General Training Daily Logs')->assertSee('0 of 5 required Daily Logs for Week 2');
        $firstCount = ParticipantNotification::query()->where('user_id', $participant->id)->count();
        GenerateParticipantNotificationsJob::dispatchSync($participant->id);
        $this->assertSame($firstCount, ParticipantNotification::query()->where('user_id', $participant->id)->count());
    }

    public function test_page_views_only_read_notifications(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $participant->participantNotifications()->delete();

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertDontSee('General Training Daily Logs incomplete');
        $this->actingAs($participant)->get(route('participant.notifications'))->assertOk();

        $this->assertDatabaseCount('participant_notifications', 0);
    }

    public function test_general_training_logs_are_independent_and_resolve_at_five(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $this->seed(QaTrainingProgramSeeder::class);
        foreach (range(1, 3) as $day) {
            DailyLog::factory()->create(['user_id' => $participant->id, 'activity_id' => null, 'logged_on' => now()->subDays($day)]);
        }
        $service = app(ParticipantNotificationService::class);
        $service->sync($participant);
        $notification = $participant->participantNotifications()->where('key', 'general_daily_logs')->firstOrFail();
        $this->assertStringContainsString('3 of 5', $notification->body);
        foreach (range(4, 5) as $day) {
            DailyLog::factory()->create(['user_id' => $participant->id, 'activity_id' => null, 'logged_on' => now()->subDays($day)]);
        }
        $service->sync($participant->fresh());
        $this->assertNotNull($participant->participantNotifications()->where('key', 'general_daily_logs')->first()->resolved_at);
    }

    public function test_revision_reminder_is_a_high_priority_needs_revision_notification(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);

        app(ParticipantNotificationService::class)->sync($participant);

        $revision = $participant->participantNotifications()->where('key', "week:{$week->id}:activity:{$activity->id}:revision")->firstOrFail();
        $this->assertSame(ParticipantNotificationType::NeedsRevision, $revision->type);
        $this->assertSame(ParticipantNotificationPriority::High, $revision->priority);
        $this->assertNull($revision->resolved_at);
        $review = $participant->participantNotifications()->where('key', "week:{$week->id}:activity:{$activity->id}:review")->firstOrFail();
        $this->assertSame(ParticipantNotificationType::AwaitingReview, $review->type);
        $this->assertSame(ParticipantNotificationPriority::Normal, $review->priority);
        $this->assertNotNull($review->resolved_at);
    }

    #[DataProvider('latestReviewDecisions')]
    public function test_latest_review_decision_sets_the_revision_reminder_and_the_approved_event(ReviewDecision $decision, bool $isRevisionRequired, bool $isApprovedEvent): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'under_review']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $review = SubmissionReview::factory()->for($version, 'submissionVersion')->create(['decision' => $decision]);

        app(ParticipantNotificationService::class)->sync($participant);

        $revision = $participant->participantNotifications()->where('key', "week:{$week->id}:activity:{$activity->id}:revision")->firstOrFail();
        $this->assertSame($isRevisionRequired, $revision->resolved_at === null);
        $this->assertSame($isApprovedEvent, $participant->participantNotifications()->where('key', "event:review:{$review->id}")->where('type', ParticipantNotificationType::ActivityApproved)->exists());
    }

    /**
     * Each decision a coach can review with, whether it keeps the revision reminder active and whether it raises the
     * "Activity approved" event.
     *
     * @return array<string, array{ReviewDecision, bool, bool}>
     */
    public static function latestReviewDecisions(): array
    {
        return [
            'approved' => [ReviewDecision::Approved, false, true],
            'needs revision' => [ReviewDecision::NeedsRevision, true, false],
            'rejected' => [ReviewDecision::Rejected, false, false],
        ];
    }

    public function test_dashboard_next_action_skips_notifications_awaiting_review(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $participant->participantNotifications()->delete();
        ParticipantNotification::factory()->for($participant, 'participant')->create(['title' => 'Activity incomplete', 'body' => 'Complete your activity.', 'created_at' => now()->subHour()]);
        ParticipantNotification::factory()->for($participant, 'participant')->create(['type' => ParticipantNotificationType::AwaitingReview, 'title' => 'Awaiting coach review', 'body' => 'Your submission is awaiting review.']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['NEXT ACTION', 'Activity incomplete — Complete your activity.'])
            ->assertDontSee('Awaiting coach review — ')
            ->assertSee('Your submission is awaiting review.');
    }

    public function test_notifications_are_paginated_and_participant_isolation_is_enforced(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $other = User::factory()->create(['role' => 'participant']);
        ParticipantNotification::factory()->count(26)->create(['user_id' => $participant->id]);
        $foreign = ParticipantNotification::factory()->create(['user_id' => $other->id]);
        $this->actingAs($participant)->get(route('participant.notifications'))->assertOk()->assertSee('Next');
        $this->actingAs($participant)->post(route('participant.notifications.read', $foreign))->assertNotFound();
    }
}
