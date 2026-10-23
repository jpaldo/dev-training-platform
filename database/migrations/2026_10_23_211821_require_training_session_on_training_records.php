<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `training_session_id` required on `activity_submissions`, `gate_evaluations` and `weekly_video_recordings`
 * (9.4, PAR-3). 9.1 added the column nullable while the app still wrote these rows without a session; since 9.3 and
 * 9.4 every write carries one. The migration refuses to run while any of the tables holds a row without a session,
 * naming those tables, because such a row has no session to belong to.
 *
 * Only the column's nullability changes: on MySQL `modify` keeps the foreign key (restrict on delete) and the per-session
 * keys, and on SQLite the table rebuild copies them.
 */
return new class extends Migration
{
    private const TABLES = ['activity_submissions', 'gate_evaluations', 'weekly_video_recordings'];

    /**
     * Run the migrations.
     *
     * @throws RuntimeException when one of the tables holds a row without a session
     */
    public function up(): void
    {
        $this->ensureEveryRowHasASession();

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedBigInteger('training_session_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations: the column is nullable again.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedBigInteger('training_session_id')->nullable()->change();
            });
        }
    }

    /**
     * @throws RuntimeException when one of the tables holds a row without a session
     */
    private function ensureEveryRowHasASession(): void
    {
        $tablesWithoutSession = array_values(array_filter(
            self::TABLES,
            fn (string $table): bool => DB::table($table)->whereNull('training_session_id')->exists(),
        ));

        if ($tablesWithoutSession !== []) {
            throw new RuntimeException(sprintf(
                'Cannot make training_session_id required: %s still have rows without a training session. Give those rows their session or delete them, then run the migration again.',
                implode(', ', $tablesWithoutSession),
            ));
        }
    }
};
