<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropTrainingPathColumnsMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_path_columns_are_gone_and_role_and_activity_position_stay_indexed(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'training_path'));
        $this->assertFalse(Schema::hasColumn('activity_requirements', 'participant_path'));
        $this->assertTrue(Schema::hasIndex('users', ['role']));
        $this->assertTrue(Schema::hasIndex('activity_requirements', ['activity_id', 'position']));
    }
}
