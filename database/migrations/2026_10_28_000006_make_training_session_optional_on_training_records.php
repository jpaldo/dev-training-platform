<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The training records that point at a session.
     *
     * @var list<string>
     */
    private const TABLES = ['activity_submissions', 'gate_evaluations', 'weekly_video_recordings'];

    /**
     * Makes the session optional on submissions, Gate evaluations and weekly videos: the restricting foreign key goes
     * and `training_session_id` becomes nullable so code can stop writing it. The per-session indexes and unique keys
     * stay until the column is dropped.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['training_session_id']);
                $table->foreignId('training_session_id')->nullable()->change();
            });
        }
    }

    /**
     * Restores the required session with its restricting foreign key. Fails when a record has no session.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('training_session_id')->nullable(false)->change();
                $table->foreign('training_session_id')->references('id')->on('training_sessions')->restrictOnDelete();
            });
        }
    }
};
