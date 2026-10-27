<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives every week optional start and end dates. A week without dates is unscheduled.
     */
    public function up(): void
    {
        Schema::table('training_weeks', function (Blueprint $table): void {
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('training_weeks', function (Blueprint $table): void {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
