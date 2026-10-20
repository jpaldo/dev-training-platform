<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 190);
            $table->string('type', 60);
            $table->string('title');
            $table->text('body');
            $table->text('action_url')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->boolean('is_read')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'is_read', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_notifications');
    }
};
