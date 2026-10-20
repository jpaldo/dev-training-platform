<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_score_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_version_id')->constrained()->cascadeOnDelete();
            $table->json('dimensions');
            $table->text('overall_feedback')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique('submission_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_score_suggestions');
    }
};
