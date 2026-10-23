<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the users' Google Drive connections: tokens, scopes and the chosen destination folder.
     */
    public function up(): void
    {
        Schema::create('google_drive_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_account_id')->nullable();
            $table->string('google_email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('drive_folder_id')->nullable();
            $table->string('drive_folder_name')->nullable();
            $table->string('drive_resource_key')->nullable();
            $table->string('drive_id')->nullable();
            $table->string('destination_source')->default('legacy');
            $table->timestamp('destination_selected_at')->nullable();
            $table->string('last_export_file_id')->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_drive_connections');
    }
};
