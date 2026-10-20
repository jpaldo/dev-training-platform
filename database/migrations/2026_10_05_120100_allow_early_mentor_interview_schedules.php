<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('week_four_mentor_interview_schedules') && Schema::hasColumn('week_four_mentor_interview_schedules', 'activity_id')) {
            Schema::table('week_four_mentor_interview_schedules', function (Blueprint $table): void {
                $table->foreignId('activity_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Existing early schedules intentionally keep a nullable activity reference.
    }
};
