<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_syncs', function (Blueprint $table): void {
            $table->string('destination_resource_key')->nullable()->after('destination_folder_id');
            $table->string('destination_drive_id')->nullable()->after('destination_resource_key');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_syncs', function (Blueprint $table): void {
            $table->dropColumn(['destination_resource_key', 'destination_drive_id']);
        });
    }
};
