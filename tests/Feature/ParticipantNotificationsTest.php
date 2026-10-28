<?php

namespace Tests\Feature;

use App\Enums\ParticipantNotificationPriority;
use App\Enums\ParticipantNotificationType;
use App\Enums\ReviewDecision;
use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\ParticipantNotificationService;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_participant_gets_week_reminders_without_daily_log_reminders_or_duplicates(): void
    {
        $program = $this->seedL4InItsFirstWeek();
        $participant = User::factory()->create(['role' => 'participant']);
        $this->join($participant, $program);
        GenerateParticipantNotificationsJob::dispatchSync($participant->id);

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
        $participant = User::factory()->create(['role' => 'participant']);
        $week = $this->seedL4InItsFirstWeek()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);

        $program = $this->join($participant, $week->program);
        app(ParticipantNotificationService::class)->sync($participant);

        $revision = $participant->participantNotifications()->where('key', "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:revision")->firstOrFail();
        $this->assertSame(ParticipantNotificationType::NeedsRevision, $revision->type);
        $this->assertSame(ParticipantNotificationPriority::High, $revision->priority);
        $this->assertNull($revision->resolved_at);
        $review = $participant->participantNotifications()->where('key', "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:review")->firstOrFail();
        $this->assertSame(ParticipantNotificationType::AwaitingReview, $review->type);
        $this->assertSame(ParticipantNotificationPriority::Normal, $review->priority);
        $this->assertNotNull($review->resolved_at);
    }

    #[DataProvider('latestReviewDecisions')]
    public function test_latest_review_decision_sets_the_revision_reminder_and_the_approved_event(ReviewDecision $decision, bool $isRevisionRequired, bool $isApprovedEvent): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $week = $this->seedL4InItsFirstWeek()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'under_review']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $review = SubmissionReview::factory()->for($version, 'submissionVersion')->create(['decision' => $decision]);

        $program = $this->join($participant, $week->program);
        app(ParticipantNotificationService::class)->sync($participant);

        $revision = $participant->participantNotifications()->where('key', "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:revision")->firstOrFail();
        $this->assertSame($isRevisionRequired, $revision->resolved_at === null);
        $this->assertSame($isApprovedEvent, $participant->participantNotifications()->where('key', "program:{$program->id}:event:review:{$review->id}")->where('type', ParticipantNotificationType::ActivityApproved)->exists());
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
        $participant = User::factory()->create(['role' => 'participant']);
        $week = $this->seedL4InItsFirstWeek()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'failed', 'attempt_number' => 1]);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status, 'attempt_number' => 2]);

        $program = $this->join($participant, $week->program);
        app(ParticipantNotificationService::class)->sync($participant);

        $reminderKeys = collect(['incomplete', 'draft', 'revision', 'review'])->mapWithKeys(fn (string $reminder): array => ["program:{$program->id}:week:{$week->id}:activity:{$activity->id}:{$reminder}" => $reminder]);
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
        $participant = User::factory()->create(['role' => 'participant']);
        $week = $this->seedL4InItsFirstWeek()->weeks()->where('week_number', 1)->firstOrFail();
        foreach ($week->activities()->where('is_active', true)->orderBy('position')->get()->values() as $index => $activity) {
            ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $statuses[$index % count($statuses)]]);
        }

        $program = $this->join($participant, $week->program);
        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertSame($isVideoReminderActive, $participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$week->id}:video")->exists());
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
        $this->join($participant, $program);

        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertTrue($participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$weeks[2]->id}:video")->exists());
        $this->assertTrue($participant->participantNotifications()->where('key', "program:{$program->id}:week:{$weeks[3]->id}:activity:{$weeks[3]->activities()->sole()->id}:incomplete")->exists());
        $this->assertFalse($participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$weeks[3]->id}:video")->exists());
    }

    public function test_an_unready_week_raises_the_requirements_reminder_and_a_completed_week_the_completed_event(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
        Activity::factory()->for($week, 'week')->create();
        $program = $this->join($participant, $week->program);

        app(ParticipantNotificationService::class)->sync($participant);

        $reminder = $participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$week->id}:gate")->sole();
        $this->assertSame(ParticipantNotificationType::GateIncomplete, $reminder->type);
        $this->assertSame('Week 2 requirements incomplete', $reminder->title);
        $this->assertSame('You still have requirements to complete before your mentor can mark Week 2 completed.', $reminder->body);

        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        app(ParticipantNotificationService::class)->sync($participant);

        $completed = $participant->participantNotifications()->active()->where('key', "program:{$program->id}:event:gate:{$evaluation->id}")->sole();
        $this->assertSame(ParticipantNotificationType::GatePassed, $completed->type);
        $this->assertSame('Week 2 completed', $completed->title);
        $this->assertSame('Your mentor marked Week 2 completed.', $completed->body);
        $this->assertFalse($participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$week->id}:gate")->exists());
    }

    public function test_activity_and_week_notifications_link_through_the_participants_curriculum_and_a_participant_in_no_curriculum_gets_none(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $activity = Activity::factory()->for($week, 'week')->create();
        $program = $this->join($member, $week->program);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($member, 'participant')->create(['status' => 'passed']);

        app(ParticipantNotificationService::class)->sync($member);
        app(ParticipantNotificationService::class)->sync($outsider);

        $incompleteKey = "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:incomplete";
        $this->assertSame(route('training.activities.show', [$program, $week, $activity]), $member->participantNotifications()->where('key', $incompleteKey)->sole()->action_url);
        $this->assertSame(route('training.weeks.show', [$program, $week]), $member->participantNotifications()->where('key', "program:{$program->id}:event:gate:{$evaluation->id}")->sole()->action_url);
        $this->assertFalse($outsider->participantNotifications()->exists());
    }

    public function test_a_participant_in_two_curricula_gets_separate_notifications_linking_within_each_curriculum(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $openActivity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $draftActivity = Activity::factory()->for($week, 'week')->create(['position' => 2]);
        $otherWeek = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $otherActivity = Activity::factory()->for($otherWeek, 'week')->create(['position' => 1]);
        $program = $this->join($participant, $week->program);
        $otherProgram = $this->join($participant, $otherWeek->program);
        ActivitySubmission::factory()->for($draftActivity)->for($participant, 'participant')->create(['status' => 'draft']);

        app(ParticipantNotificationService::class)->sync($participant);

        $activeLinks = $participant->participantNotifications()->active()->pluck('action_url', 'key')->all();
        $this->assertSame(route('training.activities.show', [$program, $week, $openActivity]), $activeLinks["program:{$program->id}:week:{$week->id}:activity:{$openActivity->id}:incomplete"]);
        $this->assertSame(route('training.submission.edit', [$program, $week, $draftActivity]), $activeLinks["program:{$program->id}:week:{$week->id}:activity:{$draftActivity->id}:draft"]);
        $this->assertArrayNotHasKey("program:{$program->id}:week:{$week->id}:activity:{$draftActivity->id}:incomplete", $activeLinks);
        $this->assertSame(route('training.activities.show', [$otherProgram, $otherWeek, $otherActivity]), $activeLinks["program:{$otherProgram->id}:week:{$otherWeek->id}:activity:{$otherActivity->id}:incomplete"]);
        $this->assertCount(3, $activeLinks);
    }

    public function test_removing_the_participant_from_a_curriculum_resolves_that_curriculums_notifications(): void
    {
        $participant = User::factory()->create();
        $leftWeek = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        Activity::factory()->for($leftWeek, 'week')->create();
        $keptWeek = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $keptActivity = Activity::factory()->for($keptWeek, 'week')->create();
        $leftProgram = $this->join($participant, $leftWeek->program);
        $keptProgram = $this->join($participant, $keptWeek->program);
        app(ParticipantNotificationService::class)->sync($participant);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->delete(route('admin.programs.participants.destroy', [$leftProgram, $participant]))
            ->assertRedirect();

        $leftNotifications = $participant->participantNotifications()->where('key', 'like', "program:{$leftProgram->id}:%")->get();
        $this->assertNotEmpty($leftNotifications);
        $this->assertTrue($leftNotifications->every(fn (ParticipantNotification $notification): bool => $notification->resolved_at !== null));
        $this->assertTrue($participant->participantNotifications()->active()->where('key', "program:{$keptProgram->id}:week:{$keptWeek->id}:activity:{$keptActivity->id}:incomplete")->exists());
    }

    public function test_a_completed_curriculum_raises_no_notifications_and_its_active_ones_are_resolved(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->ended()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $program = $this->join($participant, $week->program);
        $reminder = ParticipantNotification::factory()->for($participant, 'participant')->create(['key' => "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:incomplete"]);

        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertNotNull($reminder->fresh()->resolved_at);
        $this->assertSame(1, $participant->participantNotifications()->count());
    }

    public function test_notifications_stored_without_their_curriculum_or_with_a_session_key_are_replaced_by_per_curriculum_ones(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $program = $this->join($participant, $week->program);
        $oldReminder = ParticipantNotification::factory()->for($participant, 'participant')->create([
            'key' => "week:{$week->id}:activity:{$activity->id}:incomplete",
            'action_url' => url("/training/weeks/{$week->id}/activities/{$activity->id}"),
        ]);
        $oldEvent = ParticipantNotification::factory()->for($participant, 'participant')->create([
            'key' => 'event:review:1',
            'type' => ParticipantNotificationType::ActivityApproved,
            'action_url' => url("/training/weeks/{$week->id}/activities/{$activity->id}/submission"),
            'resolved_at' => now(),
        ]);
        $sessionReminder = ParticipantNotification::factory()->for($participant, 'participant')->create([
            'key' => "session:1:week:{$week->id}:activity:{$activity->id}:incomplete",
            'action_url' => url("/training/sessions/1/weeks/{$week->id}/activities/{$activity->id}"),
        ]);

        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertModelMissing($oldReminder);
        $this->assertModelMissing($oldEvent);
        $this->assertModelMissing($sessionReminder);
        $this->assertSame(
            route('training.activities.show', [$program, $week, $activity]),
            $participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$week->id}:activity:{$activity->id}:incomplete")->sole()->action_url,
        );
    }

    public function test_the_sync_reads_with_a_fixed_number_of_queries_whatever_the_weeks_and_activities(): void
    {
        $this->assertSame($this->repeatedSyncQueryCount(1), $this->repeatedSyncQueryCount(3));
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
        $this->join($participant, $program);

        app(ParticipantNotificationService::class)->sync($participant);

        $activeKeys = $participant->participantNotifications()->active()->pluck('key');
        $this->assertContains("program:{$program->id}:week:{$weeks[1]->id}:activity:{$openActivity->id}:incomplete", $activeKeys);
        $this->assertContains("program:{$program->id}:week:{$weeks[1]->id}:activity:{$submittedActivity->id}:review", $activeKeys);
        $this->assertContains("program:{$program->id}:week:{$weeks[1]->id}:gate", $activeKeys);
        $this->assertNotContains("program:{$program->id}:week:{$weeks[1]->id}:video", $activeKeys);

        ActivitySubmission::factory()->for($participant, 'participant')->for($openActivity)->create(['status' => 'submitted']);
        app(ParticipantNotificationService::class)->sync($participant);

        $videoReminder = $participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$weeks[1]->id}:video")->sole();
        $this->assertSame(ParticipantNotificationType::MissingVideo, $videoReminder->type);
        $this->assertSame('Weekly Video Recording missing', $videoReminder->title);
        $this->assertSame('Choose your Week 1 video from Google Drive.', $videoReminder->body);
        $this->assertSame(route('training.video-recording.edit', [$program, $weeks[1]]), $videoReminder->action_url);

        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($weeks[1], 'week')->create();
        app(ParticipantNotificationService::class)->sync($participant);

        $this->assertFalse($participant->participantNotifications()->active()->where('key', "program:{$program->id}:week:{$weeks[1]->id}:video")->exists());
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

    /**
     * Build two curricula with the given number of weeks, each with that many activities, a weekly video Gate and every
     * kind of participant work, sync once, and count the queries of a second sync, which changes nothing.
     */
    private function repeatedSyncQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        $programs = Program::factory()->count(2)->withParticipants($participant)->create();

        foreach ($programs as $program) {
            foreach (range(1, $rows) as $position) {
                $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position, 'position' => $position]);
                $gate = TrainingGate::factory()->for($week, 'week')->create();
                GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
                $activities = Activity::factory()->count($rows)->for($week, 'week')->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index + 1])->create();
                GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
                WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();

                foreach ($activities as $activity) {
                    $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create())->create();
                    $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'approved']);
                    $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'approved']);
                    SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
                    SubmissionReview::factory()->for($version, 'submissionVersion')->create(['decision' => ReviewDecision::Approved]);
                }
            }
        }

        $service = app(ParticipantNotificationService::class);
        $service->sync($participant);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->sync($participant->fresh());
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Seed the L4 curriculum at 9:00 on the day its first week opens (its weeks are dated from 9 Nov 2026), so week 1
     * is open, and return it.
     */
    private function seedL4InItsFirstWeek(): Program
    {
        $this->travelTo(L4TrainingProgramSeeder::FIRST_WEEK_STARTS_ON.' 09:00:00');
        $this->seed(L4TrainingProgramSeeder::class);

        return Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->sole();
    }

    /**
     * Add the participant to the curriculum and return it.
     */
    private function join(User $participant, Program $program): Program
    {
        $program->participants()->attach($participant, ['joined_at' => now()]);

        return $program;
    }
}
