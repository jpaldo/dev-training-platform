<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\MentorAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_and_admin_can_filter_and_export_participant_attendance_and_an_old_path_filter_is_ignored(): void
    {
        $manual = User::factory()->create();
        $automation = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([$manual, $automation] as $participant) {
            MentorAssignment::factory()->create(['participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
        AttendanceSession::create(['user_id' => $manual->id, 'check_in_at' => now()->subHours(2), 'check_out_at' => now(), 'total_seconds' => 7200]);
        AttendanceSession::create(['user_id' => $automation->id, 'check_in_at' => now()->subHour(), 'check_out_at' => null]);

        $this->actingAs($coach)->get(route('coach.attendance.index', ['path' => 'automation']))->assertOk()->assertSessionHasNoErrors()->assertSee($manual->name)->assertSee($automation->name);
        $csv = $this->actingAs($coach)->get(route('coach.attendance.export', ['path' => 'automation']))->assertOk()->assertSessionHasNoErrors()->streamedContent();
        $this->assertStringContainsString($manual->email, $csv);
        $this->assertStringContainsString($automation->email, $csv);
        $this->actingAs($coach)->get(route('coach.attendance.export', ['status' => 'active']))->assertDownload('attendance.csv');
        $this->actingAs($admin)->get(route('admin.attendance.index'))->assertOk()->assertSee($manual->name)->assertSee($automation->name);
        $this->actingAs($admin)->get(route('admin.attendance.export'))->assertDownload('attendance.csv');
    }

    public function test_export_applies_date_participant_and_completed_status_filters(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['employee_id' => 'EMP-1001']);
        $otherParticipant = User::factory()->create();
        foreach ([$participant, $otherParticipant] as $mentee) {
            MentorAssignment::factory()->create(['participant_id' => $mentee->id, 'mentor_id' => $coach->id]);
        }
        $inRange = Carbon::parse('2026-09-10 09:00:00', config('app.timezone'));
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => $inRange, 'check_out_at' => $inRange->copy()->addHour(), 'total_seconds' => 3600]);
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => $inRange->copy()->addDay(), 'check_out_at' => null]);
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => $inRange->copy()->subDays(5), 'check_out_at' => $inRange->copy()->subDays(5)->addHour(), 'total_seconds' => 3600]);
        AttendanceSession::create(['user_id' => $otherParticipant->id, 'check_in_at' => $inRange, 'check_out_at' => $inRange->copy()->addHour(), 'total_seconds' => 3600]);

        $csv = $this->actingAs($coach)->get(route('coach.attendance.export', [
            'date_from' => '2026-09-09',
            'date_to' => '2026-09-11',
            'participant' => $participant->id,
            'status' => 'completed',
        ]))->assertOk()->streamedContent();

        $rows = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(2, $rows);
        $this->assertSame(['Participant', 'Employee ID', 'Email', 'Date', 'Check-in', 'Check-out', 'Total Hours', 'Session Status'], str_getcsv($rows[0]));
        $this->assertSame([$participant->name, 'EMP-1001', $participant->email], array_slice(str_getcsv($rows[1]), 0, 3));
        $this->assertStringContainsString($participant->email, $rows[1]);
        $this->assertStringContainsString('2026-09-10', $rows[1]);
        $this->assertStringContainsString('Checked out', $rows[1]);
    }

    public function test_participant_cannot_open_the_attendance_management_pages(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get(route('admin.attendance.index'))->assertForbidden();
        $this->actingAs($participant)->get(route('coach.attendance.index'))->assertForbidden();
    }

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
