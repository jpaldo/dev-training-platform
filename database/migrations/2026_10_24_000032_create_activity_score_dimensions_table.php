<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the points and reasoning per scoring dimension of a score (one row per dimension).
     */
    public function up(): void
    {
        Schema::create('activity_score_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_score_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 2);
            $table->unsignedTinyInteger('points');
            $table->text('evidence_reasoning');
            $table->timestamps();
            $table->unique(['activity_score_id', 'dimension']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_score_dimensions');
    }
};
