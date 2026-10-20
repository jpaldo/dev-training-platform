<?php

namespace Tests\Feature;

use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\DailyLog;
use App\Models\ParticipantNotification;
use App\Models\User;
use App\Services\ParticipantNotificationService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
