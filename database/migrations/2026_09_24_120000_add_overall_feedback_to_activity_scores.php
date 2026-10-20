<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_scores', function (Blueprint $table): void {
            $table->text('overall_feedback')->nullable()->after('performance_band');
        });

        Schema::table('activity_score_histories', function (Blueprint $table): void {
            $table->text('previous_overall_feedback')->nullable()->after('new_reasoning');
            $table->text('new_overall_feedback')->nullable()->after('previous_overall_feedback');
        });
    }

    public function down(): void
    {
        Schema::table('activity_score_histories', function (Blueprint $table): void {
            $table->dropColumn(['previous_overall_feedback', 'new_overall_feedback']);
        });

        Schema::table('activity_scores', function (Blueprint $table): void {
            $table->dropColumn('overall_feedback');
        });
    }
};
