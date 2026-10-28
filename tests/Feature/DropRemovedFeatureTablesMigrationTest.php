<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropRemovedFeatureTablesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_recovery_requests_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('activity_recovery_requests'));
    }
}
