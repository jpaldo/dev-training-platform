<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            // Existing records were created by the completed Lab workflow before
            // explicit submission state existed, so preserve them as submitted.
            $table->string('status')->default('submitted')->after('evidence_files');
            $table->timestamp('submitted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->dropColumn(['status', 'submitted_at']);
        });
    }
};
