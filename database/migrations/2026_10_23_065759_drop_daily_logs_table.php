<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the daily logs (REM-7): drops the `daily_logs` table. The 2.1 migration already emptied it. No other table
 * has a foreign key to it, and its own keys (`user_id`, `activity_id`) go with it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('daily_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Daily logs are removed; rolling back does not restore their table.
    }
};
