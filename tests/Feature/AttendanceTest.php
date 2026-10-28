<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_helpers_handle_short_and_cross_midnight_sessions(): void
    {
        $participant = User::factory()->create();
        $short = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => Carbon::parse('2026-09-10 09:00:00'), 'check_out_at' => Carbon::parse('2026-09-10 09:30:00'), 'total_seconds' => 1800]);
        $overnight = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => Carbon::parse('2026-09-10 23:30:00'), 'check_out_at' => Carbon::parse('2026-09-11 01:00:00'), 'total_seconds' => 5400]);

        $this->assertSame(0.5, $short->totalHours());
        $this->assertSame(1.5, $overnight->totalHours());
    }

    public function test_an_open_session_counts_its_duration_from_check_in_to_now(): void
    {
        $participant = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-09-10 09:00:00', config('app.timezone')));
        $session = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()]);
        $this->travel(150)->minutes();

        $this->assertSame(9000, $session->durationSeconds());
        $this->assertSame(2.5, $session->totalHours());
    }
}
