<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->string('drive_resource_key')->nullable()->after('drive_folder_name');
            $table->string('drive_id')->nullable()->after('drive_resource_key');
            $table->string('destination_source')->default('legacy')->after('drive_id');
            $table->timestamp('destination_selected_at')->nullable()->after('destination_source');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_connections', function (Blueprint $table): void {
            $table->dropColumn(['drive_resource_key', 'drive_id', 'destination_source', 'destination_selected_at']);
        });
    }
};
