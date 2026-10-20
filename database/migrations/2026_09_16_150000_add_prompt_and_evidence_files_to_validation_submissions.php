<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->text('prompt')->nullable()->after('ai_tool');
            $table->json('evidence_files')->nullable()->after('evidence_notes');
        });
    }

    public function down(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->dropColumn(['prompt', 'evidence_files']);
        });
    }
};
