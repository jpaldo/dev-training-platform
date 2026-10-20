<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mentor_interview_availabilities')) {
            Schema::create('mentor_interview_availabilities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('mentor_id')->constrained('users')->restrictOnDelete();
                $table->date('available_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedSmallInteger('slot_duration_minutes')->default(60);
                $table->unsignedSmallInteger('capacity')->default(1);
                $table->string('status', 16)->default('open');
                $table->timestamps();
                $table->index(['mentor_id', 'available_date', 'status'], 'mentor_availability_date_status_idx');
            });
        }

        if (Schema::hasTable('week_four_mentor_interview_schedules')) {
            Schema::table('week_four_mentor_interview_schedules', function (Blueprint $table): void {
                if (! Schema::hasColumn('week_four_mentor_interview_schedules', 'availability_id')) {
                    $table->foreignId('availability_id')->nullable()->after('activity_id')->constrained('mentor_interview_availabilities')->nullOnDelete();
                }
                if (! Schema::hasColumn('week_four_mentor_interview_schedules', 'activity_id')) {
                    $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('week_four_mentor_interview_schedules') && Schema::hasColumn('week_four_mentor_interview_schedules', 'availability_id')) {
            Schema::table('week_four_mentor_interview_schedules', function (Blueprint $table): void {
                $table->dropForeign(['availability_id']);
                $table->dropColumn('availability_id');
            });
        }

        Schema::dropIfExists('mentor_interview_availabilities');
    }
};
