<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->string('artifact_name', 160)->nullable()->after('artifact_code');
        });
    }

    public function down(): void
    {
        Schema::table('validation_submissions', function (Blueprint $table): void {
            $table->dropColumn('artifact_name');
        });
    }
};
