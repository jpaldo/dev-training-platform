<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Validation Lab (REM-2): the `submission_versions.validation_submission_id` link and the
 * `validation_submissions` table. The 2.1 migration already emptied the table and deleted its files.
 *
 * On SQLite, dropping the foreign key rebuilds `submission_versions` into a new table that takes the old name, so the
 * foreign keys of the tables that point at it stay on `submission_versions` (renaming the original table instead would
 * move them to the renamed copy).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('submission_versions', 'validation_submission_id')) {
            Schema::table('submission_versions', function (Blueprint $table): void {
                $table->dropForeign(['validation_submission_id']);
                $table->dropColumn('validation_submission_id');
            });
        }

        Schema::dropIfExists('validation_submissions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The Validation Lab is removed; rolling back does not restore its table or the submission version link.
    }
};
