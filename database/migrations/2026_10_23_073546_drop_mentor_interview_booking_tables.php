<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the mentor interview booking and the availability calendar (REM-3): deletes the "mock interview
 * scheduled" participant notifications before the `mentor_interview_scheduled` notification type is removed, deletes
 * the mentor interview email and participant Calendar settings, then drops `week_four_mentor_interview_schedules`
 * (it points at the availabilities) and `mentor_interview_availabilities`. The 2.1 migration already emptied both
 * tables. No other table has a foreign key to them. It uses stored values, not enum cases or service constants, so it
 * keeps running after those are gone.
 */
return new class extends Migration
{
    private const MENTOR_INTERVIEW_SCHEDULED_TYPE = 'mentor_interview_scheduled';

    /** @var list<string> */
    private const SETTING_KEYS = ['mail.mentor_emails_enabled', 'google_drive.participant_calendar_enabled'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('participant_notifications')->where('type', self::MENTOR_INTERVIEW_SCHEDULED_TYPE)->delete();
        DB::table('application_settings')->whereIn('key', self::SETTING_KEYS)->delete();

        Schema::dropIfExists('week_four_mentor_interview_schedules');
        Schema::dropIfExists('mentor_interview_availabilities');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The mentor interview booking is removed; rolling back does not restore its tables, notifications or settings.
    }
};
