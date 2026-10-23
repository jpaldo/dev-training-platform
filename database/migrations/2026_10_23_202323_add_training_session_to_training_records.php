<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties participant work to a training session (9.1, PAR-3): adds `training_session_id` (restrict on delete) to
 * `activity_submissions`, `gate_evaluations` and `weekly_video_recordings`, and swaps their per-user keys for
 * per-session ones: submissions are indexed (`training_session_id`, `user_id`, `activity_id`, `attempt_number`), a Gate
 * evaluation is unique per (`training_session_id`, `training_gate_id`, `user_id`) and a weekly video per
 * (`training_session_id`, `user_id`, `training_week_id`).
 *
 * The column is nullable until 9.4 makes it required: until 9.3 and 9.4 pass the session, the app still creates these
 * rows without one, and a unique key treats every NULL session as distinct (the per-user `updateOrCreate` and
 * `firstOrNew` lookups still prevent duplicates meanwhile). The tables must be empty (MIG-1: 2.1 deleted the QA
 * training data), because existing rows have no session to point at; the migration refuses to run otherwise.
 *
 * On MySQL, each dropped key is the index that backs a foreign key (on `activity_id`, `training_gate_id` and
 * `user_id`), so a single-column index on that column is created before the key is dropped, and the other way round on
 * rollback. Each step is its own `Schema::table()` call so SQLite rebuilds the table (for the foreign key) before the
 * indexes change, and drops the new column only after its foreign key and indexes are gone.
 */
return new class extends Migration
{
    private const TABLES = ['activity_submissions', 'gate_evaluations', 'weekly_video_recordings'];

    /**
     * Run the migrations.
     *
     * @throws RuntimeException when one of the tables holds rows
     */
    public function up(): void
    {
        $this->ensureTablesAreEmpty();

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->foreignId('training_session_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['training_session_id', 'user_id', 'activity_id', 'attempt_number'], 'activity_submissions_session_attempt_idx');
            $table->index('activity_id');
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropIndex('activity_submissions_attempt_idx');
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->foreignId('training_session_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->unique(['training_session_id', 'training_gate_id', 'user_id'], 'gate_evaluations_session_gate_user_unique');
            $table->index('training_gate_id');
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->dropUnique(['training_gate_id', 'user_id']);
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->foreignId('training_session_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->unique(['training_session_id', 'user_id', 'training_week_id'], 'weekly_videos_session_user_week_unique');
            $table->index('user_id');
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'training_week_id']);
        });
    }

    /**
     * Reverse the migrations: drops the session columns and restores the per-user keys. Restoring a unique key fails
     * while a participant has rows for the same Gate or week in more than one session.
     */
    public function down(): void
    {
        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->unique(['user_id', 'training_week_id']);
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropForeign(['training_session_id']);
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropUnique('weekly_videos_session_user_week_unique');
            $table->dropIndex(['user_id']);
        });

        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropColumn('training_session_id');
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->unique(['training_gate_id', 'user_id']);
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->dropForeign(['training_session_id']);
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->dropUnique('gate_evaluations_session_gate_user_unique');
            $table->dropIndex(['training_gate_id']);
        });

        Schema::table('gate_evaluations', function (Blueprint $table): void {
            $table->dropColumn('training_session_id');
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->index(['activity_id', 'user_id', 'attempt_number'], 'activity_submissions_attempt_idx');
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropForeign(['training_session_id']);
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropIndex('activity_submissions_session_attempt_idx');
            $table->dropIndex(['activity_id']);
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropColumn('training_session_id');
        });
    }

    /**
     * @throws RuntimeException when one of the tables holds rows
     */
    private function ensureTablesAreEmpty(): void
    {
        $tablesWithRows = array_values(array_filter(
            self::TABLES,
            fn (string $table): bool => DB::table($table)->exists(),
        ));

        if ($tablesWithRows !== []) {
            throw new RuntimeException(sprintf(
                'Cannot tie training records to a training session: %s must be empty, because existing rows have no session to belong to. Delete those rows (MIG-1) and run the migration again.',
                implode(', ', $tablesWithRows),
            ));
        }
    }
};
