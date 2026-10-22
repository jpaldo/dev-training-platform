<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\ParticipantNotification;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropMentorInterviewBookingTablesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_deletes_the_mentor_interview_notifications_and_settings_and_keeps_every_other_row(): void
    {
        $participant = User::factory()->create();
        DB::table('participant_notifications')->insert([
            'user_id' => $participant->id,
            'key' => 'mentor-interview:1',
            'type' => 'mentor_interview_scheduled',
            'title' => 'Mock interview scheduled',
            'body' => 'Your mock interview is scheduled.',
        ]);
        $keptNotification = ParticipantNotification::factory()->create(['user_id' => $participant->id]);
        DB::table('application_settings')->insert([
            ['key' => 'mail.mentor_emails_enabled', 'value' => '1', 'is_encrypted' => false],
            ['key' => 'google_drive.participant_calendar_enabled', 'value' => '1', 'is_encrypted' => false],
            ['key' => 'mail.from.address', 'value' => 'training@example.test', 'is_encrypted' => false],
        ]);

        $this->migration()->up();

        $this->assertSame([$keptNotification->id], ParticipantNotification::query()->pluck('id')->all());
        $this->assertSame(['mail.from.address'], ApplicationSetting::query()->pluck('key')->all());
        $this->assertFalse(Schema::hasTable('week_four_mentor_interview_schedules'));
        $this->assertFalse(Schema::hasTable('mentor_interview_availabilities'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_23_073546_drop_mentor_interview_booking_tables.php');
    }
}
