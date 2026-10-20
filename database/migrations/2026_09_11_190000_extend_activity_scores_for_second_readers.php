<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_scores', function (Blueprint $table): void {
            $table->boolean('second_reader_required')->default(false)->after('performance_band');
            $table->text('second_reasoning')->nullable()->after('second_score');
        });
        Schema::table('activity_score_histories', function (Blueprint $table): void {
            $table->json('previous_dimensions')->nullable()->after('new_score');
            $table->json('new_dimensions')->nullable()->after('previous_dimensions');
            $table->json('previous_reasoning')->nullable()->after('new_dimensions');
            $table->json('new_reasoning')->nullable()->after('previous_reasoning');
        });
    }

    public function down(): void
    {
        Schema::table('activity_score_histories', fn (Blueprint $table) => $table->dropColumn(['previous_dimensions', 'new_dimensions', 'previous_reasoning', 'new_reasoning']));
        Schema::table('activity_scores', fn (Blueprint $table) => $table->dropColumn(['second_reader_required', 'second_reasoning']));
    }
};
