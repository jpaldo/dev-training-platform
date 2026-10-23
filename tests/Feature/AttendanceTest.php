<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\AttendanceSession;
use App\Models\Program;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_participants_can_check_in_and_out_with_server_duration(): void
    {
        $participant = User::factory()->create();
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:05:00', config('app.timezone')));
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->assertDatabaseHas('attendance_sessions', ['user_id' => $participant->id, 'check_out_at' => null]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->assertSame(1, AttendanceSession::where('user_id', $participant->id)->count());

        Carbon::setTestNow(Carbon::parse('2026-09-10 11:40:00', config('app.timezone')));
        $this->actingAs($participant)->post(route('participant.attendance.check-out'))->assertRedirect();
        $session = AttendanceSession::where('user_id', $participant->id)->firstOrFail();
        $this->assertSame(9300, $session->total_seconds);
        $this->assertSame(2.58, $session->totalHours());
        $this->actingAs($participant)->post(route('participant.attendance.check-out'))->assertRedirect();
        $this->assertSame(1, AttendanceSession::where('user_id', $participant->id)->count());
        Carbon::setTestNow();
    }

    public function test_participant_only_sees_own_history_and_cannot_use_another_users_id(): void
    {
        $participant = User::factory()->create();
        $other = User::factory()->create();
        AttendanceSession::create(['user_id' => $other->id, 'check_in_at' => now()->subHour(), 'check_out_at' => now(), 'total_seconds' => 3600]);

        $this->actingAs($participant)->get(route('participant.attendance.index'))->assertOk()->assertDontSee($other->name);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'), ['user_id' => $other->id])->assertRedirect();
        $this->assertDatabaseHas('attendance_sessions', ['user_id' => $participant->id]);
        $this->assertDatabaseMissing('attendance_sessions', ['user_id' => $other->id, 'check_out_at' => null]);
        $this->actingAs($participant)->get(route('coach.attendance.index'))->assertForbidden();
    }

    public function test_activity_check_in_redirect_preserves_only_a_safe_internal_return_destination(): void
    {
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);
        $program = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail();
        $week = $program->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->firstOrFail();
        $destination = route('training.submission.edit', [$week, $activity]);

        $this->actingAs($participant)->get($destination)->assertRedirect(route('participant.attendance.index'));
        $this->actingAs($participant)->post(route('participant.attendance.check-in'), ['return_to' => $destination])->assertRedirect($destination);
        $this->assertNotNull($participant->attendanceSessions()->whereNull('check_out_at')->first());

        $this->actingAs($participant)->post(route('participant.attendance.check-out'))->assertRedirect();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'), ['return_to' => 'https://evil.example.test/phish'])->assertRedirect(route('participant.attendance.index'));

        $this->actingAs($participant)->post(route('participant.attendance.check-out'))->assertRedirect();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'), ['return_to' => '//evil.example.test/phish'])->assertRedirect(route('participant.attendance.index'));
    }

    public function test_coach_and_admin_can_filter_and_export_participant_attendance_and_an_old_path_filter_is_ignored(): void
    {
        $manual = User::factory()->create();
        $automation = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);
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

    public function test_participant_cannot_access_management_or_modify_completed_session(): void
    {
        $participant = User::factory()->create();
        $session = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHour(), 'check_out_at' => now(), 'total_seconds' => 3600]);

        $this->actingAs($participant)->get(route('admin.attendance.index'))->assertForbidden();
        $this->actingAs($participant)->post(route('participant.attendance.check-out'))->assertRedirect();
        $this->assertSame(3600, $session->fresh()->total_seconds);
    }

    public function test_duration_helpers_handle_short_and_cross_midnight_sessions(): void
    {
        $participant = User::factory()->create();
        $short = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => Carbon::parse('2026-09-10 09:00:00'), 'check_out_at' => Carbon::parse('2026-09-10 09:30:00'), 'total_seconds' => 1800]);
        $overnight = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => Carbon::parse('2026-09-10 23:30:00'), 'check_out_at' => Carbon::parse('2026-09-11 01:00:00'), 'total_seconds' => 5400]);

        $this->assertSame(0.5, $short->totalHours());
        $this->assertSame(1.5, $overnight->totalHours());
        $this->assertSame('1h 30m', $overnight->durationLabel());
    }

    public function test_dashboard_places_attendance_before_training_content_and_preserves_actions(): void
    {
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);

        $response = $this->actingAs($participant)->get(route('dashboard'))->assertOk();
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'role="tablist"'), strpos($html, 'TRAINING SESSION'));
        $this->assertLessThan(strpos($html, 'TRAINING OVERVIEW'), strpos($html, 'TRAINING SESSION'));
        $response->assertSee('NOT CHECKED IN')->assertSee('CHECK IN')->assertSee('View attendance history');

        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHours(2), 'check_out_at' => now()->subHour(), 'total_seconds' => 3600]);
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('LAST TRAINING SESSION')->assertSee('CHECK IN');
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subMinutes(30)]);
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('CHECKED IN')->assertSee('CHECK OUT');
    }

    public function test_active_dashboard_renders_a_browser_live_elapsed_timer_from_check_in_timestamp(): void
    {
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);
        $checkIn = Carbon::parse('2026-09-11 16:59:00', config('app.timezone'));
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => $checkIn]);
        $this->travelTo($checkIn->copy()->addHour()->addMinutes(2)->addSeconds(3));

        $response = $this->actingAs($participant)->get(route('dashboard'))->assertOk();

        $response->assertSee('data-check-in-at="'.$checkIn->toIso8601String().'"', false)
            ->assertSee('Date.parse(startedAt)', false)
            ->assertSee('window.setInterval(renderElapsed, 1000)', false)
            ->assertSee('remainderSeconds', false)
            ->assertSee('Elapsed 1h 02m 03s');
    }

    public function test_an_open_session_counts_its_duration_from_check_in_to_now(): void
    {
        $participant = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-09-10 09:00:00', config('app.timezone')));
        $session = AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()]);
        $this->travel(150)->minutes();

        $this->assertSame(9000, $session->durationSeconds());
        $this->assertSame('2h 30m', $session->durationLabel());
        $this->assertSame(2.5, $session->totalHours());
    }

    public function test_current_week_activity_requires_check_in_and_direct_urls_cannot_bypass_it(): void
    {
        config(['services.google_drive.enabled' => false]);
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);
        ApplicationSetting::query()->where('key', 'google_drive.enabled')->delete();
        $week = Program::firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 2)->firstOrFail();
        $activityUrl = route('training.activities.show', [$week, $activity]);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Please check in to access this week')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('aria-disabled="true"', false);
        $this->actingAs($participant)->get($activityUrl)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('attendance');

        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->actingAs($participant)->get($activityUrl)->assertOk();
    }

    public function test_checkout_relocks_new_current_week_activity_but_preserves_existing_work(): void
    {
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);
        $week = Program::firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 2)->firstOrFail();
        $activityUrl = route('training.activities.show', [$week, $activity]);

        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->actingAs($participant)->post(route('participant.attendance.check-out'))->assertRedirect();
        $this->actingAs($participant)->get($activityUrl)->assertRedirect(route('dashboard'));

        $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($participant)->get($activityUrl)->assertOk();
    }

    public function test_checkout_blocks_continuing_an_existing_editable_draft_but_allows_read_only_activity_view(): void
    {
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);
        $week = Program::firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 2)->firstOrFail();
        $submission = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'draft']);
        $submission->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => []]);

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))->assertOk();
        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week, $activity]))
            ->assertRedirect(route('participant.attendance.index'))
            ->assertSessionHasErrors('attendance');
    }

    public function test_check_in_does_not_unlock_future_weeks_and_does_not_restrict_coaches_or_admins(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seed(L4TrainingProgramSeeder::class);
        $program = Program::firstOrFail();
        $week1 = $program->weeks()->where('week_number', 1)->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week2->activities()->firstOrFail();

        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->actingAs($participant)->get(route('training.activities.show', [$week2, $activity]))->assertForbidden();
        $this->actingAs($coach)->get(route('training.activities.show', [$week1, $week1->activities()->firstOrFail()]))->assertOk();
        $this->actingAs($admin)->get(route('training.activities.show', [$week1, $week1->activities()->firstOrFail()]))->assertOk();
    }
}
