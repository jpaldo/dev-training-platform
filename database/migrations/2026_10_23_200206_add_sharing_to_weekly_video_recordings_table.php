<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how a weekly video was shared with the company's Google Workspace domain (8.16): the Drive permission the
 * app created on the file, so a replaced video's share can be removed, and when it was shared (null while it is not).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->string('drive_permission_id')->nullable()->after('drive_resource_key');
            $table->timestamp('shared_at')->nullable()->after('chosen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_video_recordings', function (Blueprint $table): void {
            $table->dropColumn(['drive_permission_id', 'shared_at']);
        });
    }
};
