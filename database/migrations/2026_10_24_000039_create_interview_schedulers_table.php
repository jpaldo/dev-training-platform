<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the interview schedulers: booking links by interview type, with their approval state and display order.
     */
    public function up(): void
    {
        Schema::create('interview_schedulers', function (Blueprint $table): void {
            $table->id();
            $table->string('interview_type', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->text('booking_url');
            $table->text('mock_booking_url')->nullable();
            $table->text('final_booking_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('approval_status', 20)->default('approved');
            $table->boolean('submitted_publicly')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['interview_type', 'is_active', 'display_order'], 'interview_scheduler_lookup_idx');
            $table->index(['approval_status', 'submitted_publicly'], 'interview_scheduler_approval_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_schedulers');
    }
};
