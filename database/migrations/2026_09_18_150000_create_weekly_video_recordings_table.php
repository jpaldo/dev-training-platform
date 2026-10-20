<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_video_recordings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->text('notes')->nullable();
            $table->string('drive_file_id')->nullable();
            $table->string('drive_destination_folder_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'training_week_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_video_recordings');
    }
};
