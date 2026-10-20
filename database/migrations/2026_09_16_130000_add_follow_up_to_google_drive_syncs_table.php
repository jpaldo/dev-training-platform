<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_drive_syncs', function (Blueprint $table): void {
            $table->string('trigger')->nullable()->after('status');
            $table->boolean('needs_follow_up')->default(false)->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_syncs', function (Blueprint $table): void {
            $table->dropColumn(['trigger', 'needs_follow_up']);
        });
    }
};
