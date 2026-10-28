<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the training sessions: `training_session_id` and its keys leave the work tables and mentor assignments,
     * which get their per-curriculum keys instead (one evaluation per Gate and one video per week for a participant),
     * every mentor assignment requires its program, and the four session tables go. Aborts before anything changes
     * when a participant has two evaluations of one Gate or two videos for one week, or an assignment has no program.
     */
    public function up(): void
    {
        $this->ensureNoDuplicateGateEvaluations();
        $this->ensureNoDuplicateWeeklyVideos();
        $this->ensureEveryMentorAssignmentHasAProgram();

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropIndex('activity_submissions_session_attempt_idx');
            $table->dropColumn('training_session_id');
            $table->index(['user_id', 'activity_id', 'attempt_number'], 'activity_submissions_user_activity_attempt_idx');
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->dropUnique('gate_evaluations_session_gate_user_unique');
            $table->dropColumn('training_session_id');
            $table->unique(['training_gate_id', 'user_id'], 'gate_evaluations_gate_user_unique');
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropUnique('weekly_videos_session_user_week_unique');
            $table->dropColumn('training_session_id');
            $table->unique(['user_id', 'training_week_id'], 'weekly_videos_user_week_unique');
        });

        Schema::table('mentor_assignments', function (Blueprint $table): void {
            $table->dropIndex('mentor_assignments_session_participant_ended_index');
            $table->dropColumn('training_session_id');
            $table->foreignId('program_id')->nullable(false)->change();
        });

        Schema::dropIfExists('training_session_resources');
        Schema::dropIfExists('training_session_mentors');
        Schema::dropIfExists('training_session_participants');
        Schema::dropIfExists('training_sessions');
    }

    /**
     * Recreates the four session tables as the squashed migrations define them and puts back a nullable
     * `training_session_id` (no foreign key) with its old keys, and an optional program on mentor assignments. The
     * data is not restored: the session tables come back empty and every record's session is null.
     */
    public function down(): void
    {
        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('weeks_count');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('starts_on');
        });

        Schema::create('training_session_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('joined_at');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('repository_url')->nullable();
            $table->timestamps();
            $table->unique(['training_session_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('training_session_mentors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['training_session_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('training_session_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week_number')->nullable();
            $table->string('title');
            $table->text('url');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['training_session_id', 'week_number']);
        });

        Schema::table('mentor_assignments', function (Blueprint $table): void {
            $table->foreignId('program_id')->nullable()->change();
            $table->foreignId('training_session_id')->nullable();
            $table->index(['training_session_id', 'participant_id', 'ended_at'], 'mentor_assignments_session_participant_ended_index');
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropUnique('weekly_videos_user_week_unique');
            $table->foreignId('training_session_id')->nullable();
            $table->unique(['training_session_id', 'user_id', 'training_week_id'], 'weekly_videos_session_user_week_unique');
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->dropUnique('gate_evaluations_gate_user_unique');
            $table->foreignId('training_session_id')->nullable();
            $table->unique(['training_session_id', 'training_gate_id', 'user_id'], 'gate_evaluations_session_gate_user_unique');
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropIndex('activity_submissions_user_activity_attempt_idx');
            $table->foreignId('training_session_id')->nullable();
            $table->index(['training_session_id', 'user_id', 'activity_id', 'attempt_number'], 'activity_submissions_session_attempt_idx');
        });
    }

    /**
     * @throws RuntimeException when a participant has two or more evaluations of one Gate
     */
    private function ensureNoDuplicateGateEvaluations(): void
    {
        $duplicate = DB::table('gate_evaluations')
            ->groupBy('training_gate_id', 'user_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('training_gate_id')
            ->orderBy('user_id')
            ->first(['training_gate_id', 'user_id']);

        if ($duplicate !== null) {
            throw new RuntimeException("User {$duplicate->user_id} has two or more evaluations of Gate {$duplicate->training_gate_id}; merge them by hand before running this migration.");
        }
    }

    /**
     * @throws RuntimeException when a participant has two or more videos for one week
     */
    private function ensureNoDuplicateWeeklyVideos(): void
    {
        $duplicate = DB::table('weekly_video_recordings')
            ->groupBy('user_id', 'training_week_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('user_id')
            ->orderBy('training_week_id')
            ->first(['user_id', 'training_week_id']);

        if ($duplicate !== null) {
            throw new RuntimeException("User {$duplicate->user_id} has two or more videos for week {$duplicate->training_week_id}; merge them by hand before running this migration.");
        }
    }

    /**
     * @throws RuntimeException when a mentor assignment has no program
     */
    private function ensureEveryMentorAssignmentHasAProgram(): void
    {
        $assignmentId = DB::table('mentor_assignments')->whereNull('program_id')->orderBy('id')->value('id');

        if ($assignmentId !== null) {
            throw new RuntimeException("Mentor assignment {$assignmentId} has no program; give it one or delete it before running this migration.");
        }
    }
};
