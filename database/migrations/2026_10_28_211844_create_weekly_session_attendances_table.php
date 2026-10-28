<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the weekly session attendance: one row per participant who joined a week's online session of a
     * curriculum (absence is the lack of a row), with who marked it and when. Deleting the week (or its curriculum) or
     * the participant deletes the row; deleting the marker keeps it with `marked_by` null.
     */
    public function up(): void
    {
        Schema::create('weekly_session_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->timestamps();
            $table->unique(['training_week_id', 'user_id'], 'weekly_session_attendances_week_user_unique');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_session_attendances');
    }
};
