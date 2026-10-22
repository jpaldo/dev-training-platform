<?php

namespace Tests\Feature;

use App\Models\ParticipantNotification;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteDailyLogParticipantNotificationsMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_deletes_the_daily_log_notifications_and_keeps_every_other_notification(): void
    {
        $participant = User::factory()->create();
        DB::table('participant_notifications')->insert([
            ['user_id' => $participant->id, 'key' => 'general_daily_logs', 'type' => 'daily_logs', 'title' => 'General Training Daily Logs incomplete', 'body' => 'You have completed 0 of 5.'],
            ['user_id' => $participant->id, 'key' => 'week:1:daily_logs', 'type' => 'daily_logs', 'title' => 'Week 2 Daily Logs incomplete', 'body' => 'You have completed 0 of 5.'],
        ]);
        $kept = ParticipantNotification::factory()->create(['user_id' => $participant->id]);

        $this->migration()->up();

        $this->assertDatabaseMissing('participant_notifications', ['type' => 'daily_logs']);
        $this->assertSame([$kept->id], ParticipantNotification::query()->pluck('id')->all());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_23_064320_delete_daily_log_participant_notifications.php');
    }
}
