<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_number')->default(1)->after('user_id');
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->index(
                ['activity_id', 'user_id', 'attempt_number'],
                'activity_submissions_attempt_idx'
            );
        });

        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropUnique(['activity_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_submissions', function (Blueprint $table): void {
            $table->dropIndex('activity_submissions_attempt_idx');
            $table->dropColumn('attempt_number');
            $table->unique(['activity_id', 'user_id']);
        });
    }
};
