<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the participant notifications about daily logs (REM-7) before the `daily_logs` notification type is removed,
 * so no stored row holds a value the `ParticipantNotificationType` enum no longer has. It uses the stored value, not the
 * enum case, so it keeps running after the case is gone.
 */
return new class extends Migration
{
    private const DAILY_LOGS_TYPE = 'daily_logs';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('participant_notifications')->where('type', self::DAILY_LOGS_TYPE)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Daily-log notifications are removed; rolling back does not restore them.
    }
};
