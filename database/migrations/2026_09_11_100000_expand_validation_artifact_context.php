<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->string('feature_area', 160)->nullable()->after('artifact_name');
            $table->string('artifact_type', 120)->nullable()->after('feature_area');
            $table->string('ai_tool', 120)->nullable()->after('artifact_type');
            $table->longText('artifact_content')->nullable()->after('ai_tool');
        });
    }

    public function down(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->dropColumn(['feature_area', 'artifact_type', 'ai_tool', 'artifact_content']);
        });
    }
};
