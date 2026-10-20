<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
