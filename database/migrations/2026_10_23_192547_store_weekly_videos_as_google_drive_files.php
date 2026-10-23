<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Stores weekly videos as files the participant chose from their own Google Drive (8.12): drops
 * `weekly_video_recordings` with its uploaded rows, creates it again with the Drive file columns, and then deletes the
 * uploaded video files from their disks. An uploaded file cannot become a Drive file, and no table points at
 * `weekly_video_recordings`, so dropping it is safe.
 *
 * It uses the query builder only (no models), so it keeps running whatever the model looks like later.
 */
return new class extends Migration
{
    private const TABLE = 'weekly_video_recordings';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $files = $this->storedFiles();

        Schema::drop(self::TABLE);

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_week_id')->constrained()->cascadeOnDelete();
            $table->string('drive_file_id');
            $table->string('drive_resource_key')->nullable();
            $table->string('name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('drive_created_at')->nullable();
            $table->timestamp('drive_modified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('chosen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'training_week_id']);
        });

        foreach ($files as $disk => $paths) {
            Storage::disk($disk)->delete($paths);
        }
    }

    /**
     * Reverse the migrations: recreates the upload table of `2026_09_18_150000_create_weekly_video_recordings_table`,
     * empty. The chosen Drive videos are dropped, and the deleted uploaded files are not restored.
     */
    public function down(): void
    {
        Schema::drop(self::TABLE);

        Schema::create(self::TABLE, function (Blueprint $table): void {
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

    /**
     * The uploaded video files, grouped by disk.
     *
     * @return array<string, list<string>>
     */
    private function storedFiles(): array
    {
        return DB::table(self::TABLE)
            ->whereNotNull('path')
            ->get(['disk', 'path'])
            ->groupBy(fn (object $file): string => $file->disk ?: 'local')
            ->map(fn (Collection $diskFiles): array => $diskFiles->pluck('path')->unique()->values()->all())
            ->all();
    }
};
