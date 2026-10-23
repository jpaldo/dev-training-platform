<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the Google Drive sync runs: destination, status, component counts and results.
     */
    public function up(): void
    {
        Schema::create('google_drive_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('destination_folder_id');
            $table->string('destination_resource_key')->nullable();
            $table->string('destination_drive_id')->nullable();
            $table->string('status')->default('enqueued');
            $table->string('trigger')->nullable();
            $table->boolean('needs_follow_up')->default(false);
            $table->unsignedInteger('total_components')->default(0);
            $table->unsignedInteger('successful_components')->default(0);
            $table->unsignedInteger('failed_components')->default(0);
            $table->unsignedInteger('skipped_components')->default(0);
            $table->json('component_results')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_drive_syncs');
    }
};
