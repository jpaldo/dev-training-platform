<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('google_drive_evidence_exports')) {
            return;
        }

        // If a previous interrupted attempt already created the repaired table,
        // finish cleanup and let the migration be safely retried.
        if (Schema::hasTable('google_drive_evidence_exports_legacy')) {
            DB::statement(<<<'SQL'
                INSERT OR IGNORE INTO google_drive_evidence_exports
                    (id, submission_evidence_id, drive_file_id, destination_folder_id, status, error, last_attempt_at, last_synced_at, created_at, updated_at)
                SELECT id, submission_evidence_id, drive_file_id, destination_folder_id, status, error, last_attempt_at, last_synced_at, created_at, updated_at
                FROM google_drive_evidence_exports_legacy
            SQL);
            Schema::dropIfExists('google_drive_evidence_exports_legacy');
            DB::statement('DROP INDEX IF EXISTS google_drive_evidence_exports_submission_evidence_id_unique');
        }

        $foreignKeys = DB::select('PRAGMA foreign_key_list(google_drive_evidence_exports)');
        $target = collect($foreignKeys)->firstWhere('from', 'submission_evidence_id');

        if (! $target || $target->table === 'submission_evidence') {
            return;
        }

        Schema::disableForeignKeyConstraints();
        // SQLite keeps the original index name when a table is renamed.
        // Remove it before recreating the table so the unique index can be
        // created with the canonical table name.
        if (Schema::hasTable('google_drive_evidence_exports_legacy')) {
            DB::statement('DROP INDEX IF EXISTS google_drive_evidence_exports_submission_evidence_id_unique');
        }
        Schema::rename('google_drive_evidence_exports', 'google_drive_evidence_exports_legacy');

        Schema::create('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_evidence_id')->unique()->constrained('submission_evidence')->cascadeOnDelete();
            $table->string('drive_file_id')->nullable();
            $table->string('destination_folder_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO google_drive_evidence_exports
                (id, submission_evidence_id, drive_file_id, destination_folder_id, status, error, last_attempt_at, last_synced_at, created_at, updated_at)
            SELECT id, submission_evidence_id, drive_file_id, destination_folder_id, status, error, last_attempt_at, last_synced_at, created_at, updated_at
            FROM google_drive_evidence_exports_legacy
        SQL);

        Schema::drop('google_drive_evidence_exports_legacy');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // This forward repair intentionally has no destructive rollback.
    }
};
