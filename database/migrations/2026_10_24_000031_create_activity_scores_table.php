<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the score of a submission version (one each), with the optional second reading and moderation.
     */
    public function up(): void
    {
        Schema::create('activity_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('scorer_id')->constrained('users');
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('total_score')->nullable();
            $table->string('performance_band')->nullable();
            $table->text('overall_feedback')->nullable();
            $table->boolean('second_reader_required')->default(false);
            $table->foreignId('second_reader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('second_score')->nullable();
            $table->text('second_reasoning')->nullable();
            $table->string('moderation_outcome')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_scores');
    }
};
