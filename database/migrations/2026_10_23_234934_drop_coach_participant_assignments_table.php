<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the global coach assignments (MEN-3, MEN-4): mentors are now assigned per training session in
 * `mentor_assignments`, so `coach_participant_assignments` is dropped with its rows. No table has a foreign key to
 * it. Rolling back recreates the empty table as `2026_09_11_180000_create_coach_participant_assignments_table` made
 * it; the dropped assignments are not restored.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('coach_participant_assignments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('coach_participant_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['coach_id', 'participant_id'], 'coach_participant_unique');
            $table->timestamps();
        });
    }
};
