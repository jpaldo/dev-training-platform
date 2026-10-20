<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_participant_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['coach_id', 'participant_id'], 'coach_participant_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_participant_assignments');
    }
};
