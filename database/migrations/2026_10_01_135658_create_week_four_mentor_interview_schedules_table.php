<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('week_four_mentor_interview_schedules')) {
            Schema::table('week_four_mentor_interview_schedules', function (Blueprint $table): void {
                $table->unique(['participant_id', 'activity_id'], 'w4_mentor_schedule_participant_activity_unique');
                $table->index(['mentor_id', 'starts_at', 'ends_at', 'status'], 'w4_mentor_schedule_mentor_time_index');
            });

            return;
        }

        Schema::create('week_four_mentor_interview_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mentor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('activity_id')->constrained('activities')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('status', 24)->default('pending');
            $table->string('google_event_id')->nullable();
            $table->string('google_meet_url', 2048)->nullable();
            $table->string('google_conference_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['participant_id', 'activity_id'], 'w4_mentor_schedule_participant_activity_unique');
            $table->index(['mentor_id', 'starts_at', 'ends_at', 'status'], 'w4_mentor_schedule_mentor_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('week_four_mentor_interview_schedules');
    }
};
