<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('activity_recovery_requests');
    }
};
