<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates a participant's attempts at an activity in a session. A session with submissions cannot be deleted.
     */
    public function up(): void
    {
        Schema::create('activity_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['training_session_id', 'user_id', 'activity_id', 'attempt_number'], 'activity_submissions_session_attempt_idx');
            $table->index('activity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_submissions');
    }
};
