<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_syncs', function (Blueprint $table): void {
            $table->json('component_results')->nullable()->after('skipped_components');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_syncs', function (Blueprint $table): void {
            $table->dropColumn('component_results');
        });
    }
};
