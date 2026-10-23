<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the Gate reviews: one per participant, Gate and session. A session with Gate reviews cannot be deleted.
     */
    public function up(): void
    {
        Schema::create('gate_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('training_gate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_ready');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->unique(['training_session_id', 'training_gate_id', 'user_id'], 'gate_evaluations_session_gate_user_unique');
            $table->index(['user_id', 'status']);
            $table->index('training_gate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_evaluations');
    }
};
