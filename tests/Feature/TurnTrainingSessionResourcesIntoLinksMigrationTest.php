<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TurnTrainingSessionResourcesIntoLinksMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FILE_PATH = 'training-sessions/1/resources/slides.pdf';

    public function test_it_deletes_file_resources_with_their_files_and_keeps_links_with_who_added_them(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::FILE_PATH, '%PDF-1.7');
        Storage::disk('local')->put('training-sessions/1/resources/unreferenced.pdf', 'kept');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $this->migration()->down();

        $fileId = DB::table('training_session_resources')->insertGetId([
            'training_session_id' => $session->id,
            'week_number' => 1,
            'title' => 'Week 1 slides',
            'type' => 'file',
            'url' => null,
            'disk' => 'local',
            'path' => self::FILE_PATH,
            'original_filename' => 'slides.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'uploaded_by' => $admin->id,
        ]);
        $linkId = DB::table('training_session_resources')->insertGetId([
            'training_session_id' => $session->id,
            'week_number' => 2,
            'title' => 'Kick-off recording',
            'type' => 'link',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'uploaded_by' => $admin->id,
        ]);

        $this->migration()->up();

        $this->assertDatabaseMissing('training_session_resources', ['id' => $fileId]);
        $this->assertDatabaseHas('training_session_resources', [
            'id' => $linkId,
            'training_session_id' => $session->id,
            'week_number' => 2,
            'title' => 'Kick-off recording',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'added_by' => $admin->id,
        ]);
        Storage::disk('local')->assertMissing(self::FILE_PATH);
        Storage::disk('local')->assertExists('training-sessions/1/resources/unreferenced.pdf');

        foreach (['type', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by'] as $column) {
            $this->assertFalse(Schema::hasColumn('training_session_resources', $column), "{$column} is still there.");
        }
    }

    public function test_the_link_is_required_and_the_adding_admin_is_cleared_when_they_are_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $urlColumn = collect(Schema::getColumns('training_session_resources'))->firstWhere('name', 'url');
        $this->assertFalse($urlColumn['nullable']);

        $resourceId = DB::table('training_session_resources')->insertGetId([
            'training_session_id' => $session->id,
            'title' => 'Slides',
            'url' => 'https://example.com/slides',
            'added_by' => $admin->id,
        ]);
        $admin->delete();

        $this->assertDatabaseHas('training_session_resources', ['id' => $resourceId, 'added_by' => null]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_23_173403_turn_training_session_resources_into_links.php');
    }
}
