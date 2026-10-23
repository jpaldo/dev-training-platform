<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the participant's GitHub repository for the session (LOG-2): `https://github.com/<owner>/<repo>`, asked on the
 * session page after joining, so it is null until they add it. Adding and dropping a plain nullable column is a simple
 * `ALTER TABLE` on both MySQL and SQLite; the table is not rebuilt, so its foreign keys are untouched.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('training_session_participants', function (Blueprint $table): void {
            $table->string('repository_url')->nullable()->after('added_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('training_session_participants', function (Blueprint $table): void {
            $table->dropColumn('repository_url');
        });
    }
};
