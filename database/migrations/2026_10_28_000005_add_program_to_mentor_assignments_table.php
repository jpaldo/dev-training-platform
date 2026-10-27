<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties mentor assignments to their program and makes the session optional: the session's foreign key goes and
     * `training_session_id` becomes nullable so code can stop writing it. The old session index stays until the
     * column is dropped.
     */
    public function up(): void
    {
        Schema::table('mentor_assignments', function (Blueprint $table): void {
            $table->dropForeign(['training_session_id']);
            $table->foreignId('training_session_id')->nullable()->change();
        });

        Schema::table('mentor_assignments', function (Blueprint $table): void {
            $table->foreignId('program_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['program_id', 'participant_id', 'ended_at'], 'mentor_assignments_program_participant_ended_index');
        });
    }

    /**
     * Drops the program and restores the required session with its cascading foreign key. Fails when an assignment
     * has no session.
     */
    public function down(): void
    {
        Schema::table('mentor_assignments', function (Blueprint $table): void {
            $table->dropForeign(['program_id']);
            $table->dropIndex('mentor_assignments_program_participant_ended_index');
            $table->dropColumn('program_id');
        });

        Schema::table('mentor_assignments', function (Blueprint $table): void {
            $table->foreignId('training_session_id')->nullable(false)->change();
            $table->foreign('training_session_id')->references('id')->on('training_sessions')->cascadeOnDelete();
        });
    }
};
