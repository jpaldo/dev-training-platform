<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->string('drive_folder_id')->nullable()->after('scopes');
            $table->string('drive_folder_name')->nullable()->after('drive_folder_id');
            $table->string('last_export_file_id')->nullable()->after('drive_folder_name');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->dropColumn(['drive_folder_id', 'drive_folder_name', 'last_export_file_id']);
        });
    }
};
