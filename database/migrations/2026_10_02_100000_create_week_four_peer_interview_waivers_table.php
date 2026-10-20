<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('week_four_peer_interview_waivers')) {
            return;
        }

        Schema::create('week_four_peer_interview_waivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('authorized_by_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('waived_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['participant_id', 'activity_id'], 'week4_peer_waiver_unique');
            $table->index(['participant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('week_four_peer_interview_waivers');
    }
};
