<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the attendance sessions: participants no longer check in and out (the check-ins were removed in
     * session-attendance 2.1–3.2), so nothing reads or writes the table. Mentors record weekly session attendance in
     * `weekly_session_attendances` instead.
     */
    public function up(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }

    /**
     * Recreates the table as the squashed create migration defines it. The data is not restored: it comes back empty.
     */
    public function down(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at')->nullable();
            $table->unsignedInteger('total_seconds')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'check_in_at']);
            $table->index(['user_id', 'check_out_at']);
        });
    }
};
