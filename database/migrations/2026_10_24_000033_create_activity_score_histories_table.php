<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the history of score changes: who changed a score, from what to what, and why.
     */
    public function up(): void
    {
        Schema::create('activity_score_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_score_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scorer_id')->constrained('users');
            $table->unsignedTinyInteger('previous_score')->nullable();
            $table->unsignedTinyInteger('new_score');
            $table->json('previous_dimensions')->nullable();
            $table->json('new_dimensions')->nullable();
            $table->json('previous_reasoning')->nullable();
            $table->json('new_reasoning')->nullable();
            $table->text('previous_overall_feedback')->nullable();
            $table->text('new_overall_feedback')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_score_histories');
    }
};
