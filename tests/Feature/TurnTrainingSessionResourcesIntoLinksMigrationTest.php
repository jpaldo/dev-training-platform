<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TurnTrainingSessionResourcesIntoLinksMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

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
}
