<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the recovery requests: participants can no longer ask a mentor to reopen a failed attempt (the feature
     * was removed in session-attendance 1.1), so nothing reads or writes the table. Mentors still open a retry from
     * the review screen.
     */
    public function up(): void
    {
        Schema::dropIfExists('activity_recovery_requests');
    }

    /**
     * Recreates the table as the squashed create migration defines it. The data is not restored: it comes back empty.
     */
    public function down(): void
    {
        Schema::create('activity_recovery_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_submission_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->text('coach_response')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['activity_submission_id', 'status']);
        });
    }
};
