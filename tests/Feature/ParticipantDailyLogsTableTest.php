<?php

namespace Tests\Feature;

use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantDailyLogsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_daily_logs_render_in_a_table_and_are_owner_scoped(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $other = User::factory()->create(['role' => 'participant']);
        DailyLog::factory()->for($participant, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-10', 'content' => 'My training work']);
        DailyLog::factory()->for($other, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-11', 'content' => 'Private other work']);

        $this->actingAs($participant)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('<table', false)
            ->assertSee('Date')
            ->assertSee('General Training')
            ->assertSee('Sep 10, 2026')
            ->assertSee('My training work')
            ->assertDontSee('Private other work')
            ->assertSee('Weekly progress')
            ->assertSee('New log');
    }

    public function test_empty_daily_logs_show_empty_state_and_new_log_action(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('No Daily Logs yet.')
            ->assertSee('New log');
    }
}
