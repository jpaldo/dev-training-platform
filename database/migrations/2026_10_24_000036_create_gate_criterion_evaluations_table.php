<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the per-criterion results of a Gate review (one per criterion).
     */
    public function up(): void
    {
        Schema::create('gate_criterion_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gate_evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gate_criterion_id')->constrained('gate_criteria')->cascadeOnDelete();
            $table->string('status')->default('not_met');
            $table->text('notes')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->unique(['gate_evaluation_id', 'gate_criterion_id'], 'gate_crit_eval_gate_crit_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_criterion_evaluations');
    }
};
