<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_exports', function (Blueprint $table): void {
            $table->timestamp('last_attempt_at')->nullable()->after('error');
        });
        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->timestamp('last_attempt_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_exports', function (Blueprint $table): void {
            $table->dropColumn('last_attempt_at');
        });
        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->dropColumn('last_attempt_at');
        });
    }
};
