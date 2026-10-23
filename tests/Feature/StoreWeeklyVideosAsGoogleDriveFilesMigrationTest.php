<?php

namespace Tests\Feature;

use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreWeeklyVideosAsGoogleDriveFilesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const VIDEO_PATH = 'weekly-video/1/1/week-1.mp4';

    public function test_it_deletes_uploaded_videos_with_their_files_and_creates_the_google_drive_columns(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::VIDEO_PATH, 'video');
        Storage::disk('local')->put('weekly-video/1/1/unreferenced.mp4', 'kept');
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create();
        $this->migration()->down();

        DB::table('weekly_video_recordings')->insert([
            'user_id' => $participant->id,
            'training_week_id' => $week->id,
            'disk' => 'local',
            'path' => self::VIDEO_PATH,
            'original_filename' => 'week-1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 5,
            'uploaded_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Storage::disk('local')->assertMissing(self::VIDEO_PATH);
        Storage::disk('local')->assertExists('weekly-video/1/1/unreferenced.mp4');
        $this->assertSame(
            ['id', 'user_id', 'training_week_id', 'drive_file_id', 'drive_resource_key', 'name', 'mime_type', 'size_bytes', 'drive_created_at', 'drive_modified_at', 'notes', 'chosen_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('weekly_video_recordings'),
        );
    }

    public function test_a_video_without_its_drive_file_id_is_refused(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('weekly_video_recordings')->insert([
            'user_id' => $participant->id,
            'training_week_id' => $week->id,
            'name' => 'week-1.mp4',
            'mime_type' => 'video/mp4',
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_23_192547_store_weekly_videos_as_google_drive_files.php');
    }
}
