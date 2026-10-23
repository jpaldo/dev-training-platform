<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the weekly videos: the Google Drive file a participant chose for a week in a session (one each). A
     * session with videos cannot be deleted.
     */
    public function up(): void
    {
        Schema::create('weekly_video_recordings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->string('drive_file_id');
            $table->string('drive_resource_key')->nullable();
            $table->string('drive_permission_id')->nullable();
            $table->string('name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('drive_created_at')->nullable();
            $table->timestamp('drive_modified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('chosen_at')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();
            $table->unique(['training_session_id', 'user_id', 'training_week_id'], 'weekly_videos_session_user_week_unique');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_video_recordings');
    }
};
