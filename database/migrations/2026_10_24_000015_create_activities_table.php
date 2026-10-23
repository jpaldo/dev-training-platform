<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the activities of a week, unique by position within it.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('objectives')->nullable();
            $table->longText('instructions')->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('requires_coach_review')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['training_week_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
