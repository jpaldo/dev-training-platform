<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the training paths (MIG-2): drops `users.training_path` and `activity_requirements.participant_path`
 * together with the indexes that include them. Nothing reads either column since 5.1 to 5.3.
 *
 * `users` keeps an index on `role`. On MySQL, `activity_req_activity_path_pos_idx` is the only index that starts with
 * `activity_id`, so it backs the `activity_id` foreign key: the replacement (`activity_id`, `position`) index is created
 * before it is dropped, and the other way round on rollback. Each column is dropped after its indexes because SQLite
 * refuses to drop an indexed column.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('role');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'training_path']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('training_path');
        });

        Schema::table('activity_requirements', function (Blueprint $table): void {
            $table->index(['activity_id', 'position'], 'activity_req_activity_pos_idx');
        });

        Schema::table('activity_requirements', function (Blueprint $table): void {
            $table->dropIndex('activity_req_activity_path_pos_idx');
        });

        Schema::table('activity_requirements', function (Blueprint $table): void {
            $table->dropColumn('participant_path');
        });
    }

    /**
     * Reverse the migrations: restores both columns (empty) and the original indexes.
     */
    public function down(): void
    {
        Schema::table('activity_requirements', function (Blueprint $table): void {
            $table->string('participant_path')->nullable()->after('activity_id');
        });

        Schema::table('activity_requirements', function (Blueprint $table): void {
            $table->index(['activity_id', 'participant_path', 'position'], 'activity_req_activity_path_pos_idx');
        });

        Schema::table('activity_requirements', function (Blueprint $table): void {
            $table->dropIndex('activity_req_activity_pos_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('training_path')->nullable()->after('role');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index(['role', 'training_path']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
        });
    }
};
