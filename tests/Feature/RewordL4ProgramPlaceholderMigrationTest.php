<?php

namespace Tests\Feature;

use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewordL4ProgramPlaceholderMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_untouched_placeholder_description_is_reworded(): void
    {
        $placeholder = Program::factory()->create(['description' => 'Placeholder — the L4 Accelerator Training curriculum is coming soon.']);
        $edited = Program::factory()->create(['description' => 'An admin wrote this curriculum description.']);

        $migration = require database_path('migrations/2026_10_28_232943_reword_l4_program_placeholder_description.php');
        $migration->up();

        $this->assertSame('Placeholder — the L4 Accelerator Training program is coming soon.', $placeholder->fresh()->description);
        $this->assertSame('An admin wrote this curriculum description.', $edited->fresh()->description);
    }
}
