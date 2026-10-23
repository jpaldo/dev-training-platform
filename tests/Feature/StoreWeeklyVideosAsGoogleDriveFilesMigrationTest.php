<?php

namespace Tests\Feature;

use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreWeeklyVideosAsGoogleDriveFilesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

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
}
