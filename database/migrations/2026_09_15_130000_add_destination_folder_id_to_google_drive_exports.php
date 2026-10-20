<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_exports', function (Blueprint $table): void {
            $table->string('destination_folder_id')->nullable()->after('drive_file_id');
        });

        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->string('destination_folder_id')->nullable()->after('drive_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_evidence_exports', function (Blueprint $table): void {
            $table->dropColumn('destination_folder_id');
        });

        Schema::table('google_drive_exports', function (Blueprint $table): void {
            $table->dropColumn('destination_folder_id');
        });
    }
};
