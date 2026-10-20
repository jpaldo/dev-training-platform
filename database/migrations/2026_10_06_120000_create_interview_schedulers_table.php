<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('interview_schedulers')) {
            return;
        }
        Schema::create('interview_schedulers', function (Blueprint $table): void {
            $table->id();
            $table->string('interview_type', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->text('booking_url');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['interview_type', 'is_active', 'display_order'], 'interview_scheduler_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_schedulers');
    }
};
