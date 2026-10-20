<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('participant_path')->nullable();
            $table->string('requirement_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position');
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'participant_path', 'position'], 'activity_req_activity_path_pos_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_requirements');
    }
};
