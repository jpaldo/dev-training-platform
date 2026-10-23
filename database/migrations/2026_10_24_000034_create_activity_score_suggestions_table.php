<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the AI score suggestion for a submission version (one each).
     */
    public function up(): void
    {
        Schema::create('activity_score_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('dimensions');
            $table->text('overall_feedback')->nullable();
            $table->json('evidence_processing')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_score_suggestions');
    }
};
