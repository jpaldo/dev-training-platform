<?php

namespace Tests\Feature;

use App\Enums\ParticipantNotificationPriority;
use App\Enums\ParticipantNotificationType;
use App\Enums\ReviewDecision;
use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ParticipantNotificationService;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_participant_gets_week_reminders_without_daily_log_reminders_or_duplicates(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('Activity incomplete')->assertDontSee('Daily Logs incomplete');
        $this->assertDatabaseMissing('participant_notifications', ['user_id' => $participant->id, 'type' => 'daily_logs']);
        $firstCount = ParticipantNotification::query()->where('user_id', $participant->id)->count();
        GenerateParticipantNotificationsJob::dispatchSync($participant->id);
        $this->assertSame($firstCount, ParticipantNotification::query()->where('user_id', $participant->id)->count());
    }

    public function test_page_views_only_read_notifications(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $participant->participantNotifications()->delete();

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertDontSee('Activity incomplete');
        $this->actingAs($participant)->get(route('participant.notifications'))->assertOk();

        $this->assertDatabaseCount('participant_notifications', 0);
    }

    public function test_revision_reminder_is_a_high_priority_needs_revision_notification(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
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
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
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

    /**
     * @param  list<string>  $activeReminders
     */
    #[DataProvider('latestAttemptStatuses')]
    public function test_latest_attempt_status_sets_the_activity_reminders(string $status, array $activeReminders): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'failed', 'attempt_number' => 1]);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status, 'attempt_number' => 2]);

        app(ParticipantNotificationService::class)->sync($participant);

        $reminderKeys = collect(['incomplete', 'draft', 'revision', 'review'])->mapWithKeys(fn (string $reminder): array => ["week:{$week->id}:activity:{$activity->id}:{$reminder}" => $reminder]);
        $this->assertSame($activeReminders, $participant->participantNotifications()->active()->whereIn('key', $reminderKeys->keys())->orderBy('id')->pluck('key')->map(fn (string $key): string => $reminderKeys[$key])->all());
    }

    /**
     * The status of the participant's latest attempt (after a failed first attempt) and the activity reminders it
     * keeps active, in the order the sync writes them.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function latestAttemptStatuses(): array
    {
        return [
            'failed' => ['failed', ['incomplete']],
            'draft' => ['draft', ['draft']],
            'submitted' => ['submitted', ['review']],
            'resubmitted' => ['resubmitted', ['review']],
            'under review' => ['under_review', ['review']],
            'approved' => ['approved', []],
            'completed' => ['completed', []],
        ];
    }

    /**
     * @param  list<string>  $statuses
     */
    #[DataProvider('weekActivityStatuses')]
    public function test_weekly_video_reminder_starts_once_every_activity_of_the_week_is_handed_in(array $statuses, bool $isVideoReminderActive): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        foreach ($week->activities()->where('is_active', true)->orderBy('position')->get()->values() as $index => $activity) {
            ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $statuses[$index % count($statuses)]]);
        }

        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertSame($isVideoReminderActive, $participant->participantNotifications()->active()->where('key', "week:{$week->id}:video")->exists());
    }

    /**
     * The statuses given to the week's activities in turn and whether the Weekly Video Recording reminder is active.
     *
     * @return array<string, array{list<string>, bool}>
     */
    public static function weekActivityStatuses(): array
    {
        return [
            'handed in or under review' => [['under_review', 'submitted', 'resubmitted', 'approved', 'completed'], true],
            'one still a draft' => [['draft', 'approved', 'approved', 'approved', 'approved'], false],
        ];
    }

    public function test_weekly_video_reminder_is_raised_only_for_weeks_whose_gate_has_a_weekly_video_criterion(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $weeks = collect([2 => true, 3 => false])->map(function (bool $hasVideoCriterion, int $weekNumber) use ($program, $participant): TrainingWeek {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
            $gate = TrainingGate::factory()->for($week, 'week')->create();
            GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => $hasVideoCriterion ? GateCriterion::TYPE_WEEKLY_VIDEO : GateCriterion::TYPE_DELIVERABLE]);
            ActivitySubmission::factory()->for($participant, 'participant')->for(Activity::factory()->for($week, 'week'))->create(['status' => 'submitted']);

            return $week;
        });
        GateEvaluation::factory()->for($weeks[2]->gates()->sole(), 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertTrue($participant->participantNotifications()->active()->where('key', "week:{$weeks[2]->id}:video")->exists());
        $this->assertTrue($participant->participantNotifications()->where('key', "week:{$weeks[3]->id}:activity:{$weeks[3]->activities()->sole()->id}:incomplete")->exists());
        $this->assertFalse($participant->participantNotifications()->active()->where('key', "week:{$weeks[3]->id}:video")->exists());
    }

    public function test_week_one_work_raises_the_activity_video_and_gate_reminders(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $weeks = collect(range(1, 4))->mapWithKeys(function (int $weekNumber) use ($program): array {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
            GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);

            return [$weekNumber => $week];
        });
        $submittedActivity = Activity::factory()->for($weeks[1], 'week')->create(['position' => 1]);
        $openActivity = Activity::factory()->for($weeks[1], 'week')->create(['position' => 2]);
        ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'submitted']);

        app(ParticipantNotificationService::class)->sync($participant);

        $activeKeys = $participant->participantNotifications()->active()->pluck('key');
        $this->assertContains("week:{$weeks[1]->id}:activity:{$openActivity->id}:incomplete", $activeKeys);
        $this->assertContains("week:{$weeks[1]->id}:activity:{$submittedActivity->id}:review", $activeKeys);
        $this->assertContains("week:{$weeks[1]->id}:gate", $activeKeys);
        $this->assertNotContains("week:{$weeks[1]->id}:video", $activeKeys);

        ActivitySubmission::factory()->for($participant, 'participant')->for($openActivity)->create(['status' => 'submitted']);
        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertTrue($participant->participantNotifications()->active()->where('key', "week:{$weeks[1]->id}:video")->exists());
    }

    public function test_dashboard_next_action_skips_notifications_awaiting_review(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $participant->participantNotifications()->delete();
        ParticipantNotification::factory()->for($participant, 'participant')->create(['title' => 'Activity incomplete', 'body' => 'Complete your activity.', 'created_at' => now()->subHour()]);
        ParticipantNotification::factory()->for($participant, 'participant')->create(['type' => ParticipantNotificationType::AwaitingReview, 'title' => 'Awaiting mentor review', 'body' => 'Your submission is awaiting review.']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['NEXT ACTION', 'Activity incomplete — Complete your activity.'])
            ->assertDontSee('Awaiting mentor review — ')
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
