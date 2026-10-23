<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Services\TrainingSessionService;
use Database\Seeders\L4FirstSessionSeeder;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class L4FirstSessionSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_running_twice_leaves_one_four_week_l4_session_from_9_nov_to_6_dec_2026_under_the_session_rules(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $this->seed(L4FirstSessionSeeder::class);
        $this->seed(L4FirstSessionSeeder::class);

        $session = TrainingSession::query()->sole();
        $program = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->sole();
        $this->assertSame('L4 Accelerator Training — November 2026', $session->title);
        $this->assertSame($program->id, $session->program_id);
        $this->assertSame(4, $session->weeks_count);
        $this->assertSame('2026-11-09', $session->starts_on->toDateString());
        $this->assertSame('2026-12-06', $session->ends_on->toDateString());
        $this->assertTrue($session->ends_on->equalTo(TrainingSessionService::endsOn($session->starts_on, $session->weeks_count)));
        $this->assertLessThanOrEqual($program->weeks()->where('is_active', true)->count(), $session->weeks_count);
    }

    public function test_seeding_is_not_an_admin_action_so_the_session_has_no_creator_and_no_audit_row(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $this->seed(L4FirstSessionSeeder::class);

        $this->assertNull(TrainingSession::query()->sole()->created_by);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_second_run_keeps_an_admins_edits_to_the_seeded_session(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $this->seed(L4FirstSessionSeeder::class);
        TrainingSession::query()->sole()->update(['weeks_count' => 3, 'ends_on' => '2026-11-29']);

        $this->seed(L4FirstSessionSeeder::class);

        $session = TrainingSession::query()->sole();
        $this->assertSame(3, $session->weeks_count);
        $this->assertSame('2026-11-29', $session->ends_on->toDateString());
    }

    public function test_throws_and_seeds_nothing_when_the_l4_program_is_missing(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->seed(L4FirstSessionSeeder::class),
            RuntimeException::class,
            'The L4 Accelerator Training program does not exist; run L4TrainingProgramSeeder before L4FirstSessionSeeder.',
        );

        $this->assertDatabaseEmpty('training_sessions');
    }
}
