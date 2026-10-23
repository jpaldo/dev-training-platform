<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the mentor assignments: which session mentor mentors a participant in a session, and since when. The
     * current assignment has no `ended_at` (at most one per participant per session, kept so by
     * MentorAssignmentService); ended rows are the history. `assigned_by` is the admin who made the assignment.
     */
    public function up(): void
    {
        Schema::create('mentor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mentor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
            $table->index(['training_session_id', 'participant_id', 'ended_at'], 'mentor_assignments_session_participant_ended_index');
            $table->index(['mentor_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentor_assignments');
    }
};
