<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Week 4 peer interviews (REM-3): drops `week_four_partner_feedback`, `week_four_interview_pairs` and
 * `week_four_peer_interview_waivers`, the feedback first because it points at the pairs. The 2.1 migration already
 * emptied them. No other table has a foreign key to them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('week_four_partner_feedback');
        Schema::dropIfExists('week_four_interview_pairs');
        Schema::dropIfExists('week_four_peer_interview_waivers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The Week 4 peer interviews are removed; rolling back does not restore their tables.
    }
};
