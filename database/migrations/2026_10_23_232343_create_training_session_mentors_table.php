<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the session mentors: the coaches who mentor participants in a training session.
     */
    public function up(): void
    {
        Schema::create('training_session_mentors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['training_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_mentors');
    }
};
