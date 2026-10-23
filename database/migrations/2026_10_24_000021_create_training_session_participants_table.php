<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the participants of a session (once each), who added them and their repository link.
     */
    public function up(): void
    {
        Schema::create('training_session_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('joined_at');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('repository_url')->nullable();
            $table->timestamps();
            $table->unique(['training_session_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_session_participants');
    }
};
