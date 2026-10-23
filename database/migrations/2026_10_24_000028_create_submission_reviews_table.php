<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the mentor reviews of a submission version.
     */
    public function up(): void
    {
        Schema::create('submission_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision');
            $table->text('comments')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['reviewer_id', 'reviewed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_reviews');
    }
};
