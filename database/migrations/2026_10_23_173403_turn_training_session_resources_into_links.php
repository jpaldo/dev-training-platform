<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Turns session resources into links only (8.9): deletes the uploaded file resources (rows and stored files), drops the
 * `type` and file columns, renames `uploaded_by` to `added_by` and makes `url` required. A row without a URL cannot
 * become a link, so it is deleted with the files.
 *
 * It uses the query builder and the stored values (not models or enums), so it keeps running after 8.9 deletes
 * `TrainingSessionResourceType`. `added_by` is added, filled from `uploaded_by` and the old column dropped (rather than
 * renamed in place) so the foreign key and its MySQL index carry the new column's name; no table points at
 * `training_session_resources`. The stored files are deleted only after the schema change has run.
 */
return new class extends Migration
{
    private const TABLE = 'training_session_resources';

    private const FILE_COLUMNS = ['type', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $files = $this->storedFiles();

        DB::table(self::TABLE)
            ->where('type', 'file')
            ->orWhereNull('url')
            ->delete();

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreignId('added_by')->nullable()->after('url')->constrained('users')->nullOnDelete();
        });

        DB::table(self::TABLE)->update(['added_by' => DB::raw('uploaded_by')]);

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropForeign(['uploaded_by']);
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn([...self::FILE_COLUMNS, 'uploaded_by']);
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->text('url')->nullable(false)->change();
        });

        foreach ($files as $disk => $paths) {
            Storage::disk($disk)->delete($paths);
        }
    }

    /**
     * Reverse the migrations: restores the file columns (empty, every kept row a link) and `uploaded_by`. Deleted file
     * resources and their stored files are not restored.
     */
    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->text('url')->nullable()->change();
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('type', 20)->default('link')->after('title');
            $table->string('disk')->nullable()->after('url');
            $table->string('path')->nullable()->after('disk');
            $table->string('original_filename')->nullable()->after('path');
            $table->string('mime_type')->nullable()->after('original_filename');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            $table->foreignId('uploaded_by')->nullable()->after('size_bytes')->constrained('users')->nullOnDelete();
        });

        DB::table(self::TABLE)->update(['uploaded_by' => DB::raw('added_by')]);

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropForeign(['added_by']);
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('added_by');
        });
    }

    /**
     * The stored files of the file resources, grouped by disk.
     *
     * @return array<string, list<string>>
     */
    private function storedFiles(): array
    {
        return DB::table(self::TABLE)
            ->where('type', 'file')
            ->whereNotNull('path')
            ->get(['disk', 'path'])
            ->groupBy(fn (object $file): string => $file->disk ?: 'local')
            ->map(fn (Collection $diskFiles): array => $diskFiles->pluck('path')->unique()->values()->all())
            ->all();
    }
};
