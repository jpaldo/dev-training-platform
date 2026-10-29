<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the repository checks: one row per check a mentor or admin started of a participant's GitHub repository
     * for a training week of a curriculum, with the ref, the expected files it looked for, the findings, the AI
     * assessment and its timings. The curriculum is reached through the week. Deleting the week (or its curriculum) or
     * the participant deletes the row; deleting who started it keeps it with `requested_by` null.
     */
    public function up(): void
    {
        Schema::create('repository_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('queued');
            $table->string('repository_url');
            $table->string('ref_input')->nullable();
            $table->json('resolved_ref')->nullable();
            $table->json('expected_files');
            $table->json('findings')->nullable();
            $table->json('assessment')->nullable();
            $table->string('assessment_note')->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('github_requests')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'training_week_id', 'id'], 'repository_checks_participant_week_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_checks');
    }
};
