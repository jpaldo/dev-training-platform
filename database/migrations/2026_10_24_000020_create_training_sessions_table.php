<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the training sessions: dated runs of a program. A program with sessions cannot be deleted.
     */
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('weeks_count');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('starts_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
