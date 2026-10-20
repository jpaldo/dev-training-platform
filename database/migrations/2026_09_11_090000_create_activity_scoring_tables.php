<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('scorer_id')->constrained('users');
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('total_score')->nullable();
            $table->string('performance_band')->nullable();
            $table->foreignId('second_reader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('second_score')->nullable();
            $table->string('moderation_outcome')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_score_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_score_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 2);
            $table->unsignedTinyInteger('points');
            $table->text('evidence_reasoning');
            $table->timestamps();
            $table->unique(['activity_score_id', 'dimension']);
        });

        Schema::create('activity_score_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_score_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scorer_id')->constrained('users');
            $table->unsignedTinyInteger('previous_score')->nullable();
            $table->unsignedTinyInteger('new_score');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_score_histories');
        Schema::dropIfExists('activity_score_dimensions');
        Schema::dropIfExists('activity_scores');
    }
};
