<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the Google Drive export tracking rows: one per user, export type, scope and destination folder.
     */
    public function up(): void
    {
        Schema::create('google_drive_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('export_type');
            $table->string('scope_key');
            $table->string('drive_file_id')->nullable();
            $table->string('destination_folder_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'export_type', 'scope_key', 'destination_folder_id'], 'google_drive_exports_destination_identity_unique');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_drive_exports');
    }
};
