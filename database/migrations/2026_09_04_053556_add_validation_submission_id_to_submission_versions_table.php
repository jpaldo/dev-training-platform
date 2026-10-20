<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('submission_versions', function (Blueprint $table) {
            $table->foreignId('validation_submission_id')->nullable()->after('activity_submission_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submission_versions', function (Blueprint $table) {
            $table->dropForeign(['validation_submission_id']);
            $table->dropColumn('validation_submission_id');
        });
    }
};
