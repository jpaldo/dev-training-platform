<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendancePagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participant_without_an_open_session_gets_check_in_now_as_the_single_main_action(): void
    {
        $participant = User::factory()->create();
        $this->seed(L4TrainingProgramSeeder::class);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $returnTo = route('training.submission.edit', [$session, $week, $week->activities()->firstOrFail()]);

        $response = $this->actingAs($participant)
            ->followingRedirects()
            ->get($returnTo)
            ->assertOk()
            ->assertSeeInOrder([
                'TRAINING ATTENDANCE',
                'Attendance',
                'id="attendance-main-action"',
                'action="'.route('participant.attendance.check-in').'"',
                'name="return_to" value="'.$returnTo.'"',
                'CHECK IN NOW',
                'role="alert"',
                'CHECK-IN REQUIRED',
                'You need to check in before you can start or continue working on this activity.',
                'NOT CHECKED IN',
                'Ready to start your training session?',
                'Session history',
                'No training sessions recorded yet.',
            ], false)
            ->assertDontSee('CHECK OUT')
            ->assertDontSee(route('participant.attendance.check-out'));

        $this->assertStringNotContainsString('data-attendance-row', $response->getContent());
    }

    public function test_participant_with_an_open_session_gets_check_out_and_the_history_newest_first(): void
    {
        $participant = User::factory()->create();
        $this->attendanceSession($participant, '2026-09-08 09:00:00', '2026-09-08 10:30:00');
        $this->attendanceSession($participant, '2026-09-10 09:05:00');

        $response = $this->actingAs($participant)->get(route('participant.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="attendance-main-action"',
                'action="'.route('participant.attendance.check-out').'"',
                'CHECK OUT',
                'CHECKED IN',
                'Current training session',
                'Started September 10, 2026 · 9:05 AM · Elapsed',
                'Session history',
                'role="region"',
                'Sep 10, 2026',
                '9:05 AM',
                'Active',
                'Sep 8, 2026',
                '9:00 AM',
                '10:30 AM',
                '1h 30m',
            ], false)
            ->assertDontSee('CHECK IN NOW')
            ->assertDontSee('CHECK-IN REQUIRED')
            ->assertDontSee('No training sessions recorded yet.');

        $this->assertSame(2, substr_count($response->getContent(), 'data-attendance-row'));
        $this->assertSame(1, substr_count($response->getContent(), 'CHECK OUT'));
    }

    public function test_check_in_returns_to_the_attendance_page_with_the_status_alert(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)
            ->followingRedirects()
            ->post(route('participant.attendance.check-in'))
            ->assertOk()
            ->assertSeeInOrder([
                'CHECK OUT',
                'role="status"',
                'Training session checked in. Your training time is calculated automatically.',
                'Current training session',
            ], false);
    }

    public function test_coach_attendance_page_shows_filters_sessions_and_an_export_main_action_for_the_same_query(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['name' => 'Ana Manual']);
        $automation = User::factory()->create(['name' => 'Ben Automation']);
        $this->attendanceSession($manual, '2026-09-09 09:00:00', '2026-09-09 11:30:00');
        $this->attendanceSession($automation, '2026-09-10 08:00:00');
        $this->attendanceSession($automation, '2026-09-08 08:00:00', '2026-09-08 09:00:00');
        $query = ['participant' => $automation->id, 'status' => 'active'];

        $response = $this->actingAs($coach)->get(route('coach.attendance.index', $query))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR · ATTENDANCE',
                'Participant Attendance',
                'id="attendance-manage-main-action"',
                'href="'.e(route('coach.attendance.export', $query)).'"',
                'Export CSV',
                'aria-label="Filter attendance"',
                'name="date_from" value=""',
                'name="date_to" value=""',
                '<option value="'.$manual->id.'" >Ana Manual</option>',
                '<option value="'.$automation->id.'" selected>Ben Automation</option>',
                '<option value="active" selected>Checked in</option>',
                '<option value="completed" >Checked out</option>',
                'Filter',
                'Attendance sessions',
                'Ben Automation',
                $automation->email,
                'Sep 10, 2026 · 8:00 AM',
                '—',
                'CHECKED IN',
            ], false)
            ->assertDontSee('ADMIN · ATTENDANCE')
            ->assertDontSee(route('admin.attendance.export'))
            ->assertDontSee('name="path"', false)
            ->assertDontSee('QA Path');

        $this->assertSame(1, substr_count($response->getContent(), 'data-attendance-row'));
        $this->assertSame(1, substr_count($response->getContent(), 'Export CSV'));
    }

    public function test_admin_attendance_page_exports_through_the_admin_route_and_shows_the_empty_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $this->attendanceSession($participant, '2026-09-09 09:00:00', '2026-09-09 11:30:00');

        $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN · ATTENDANCE',
                'href="'.route('admin.attendance.export').'"',
                $participant->name,
                'Sep 9, 2026 · 9:00 AM',
                'Sep 9, 2026 · 11:30 AM',
                '2.50',
                'CHECKED OUT',
            ], false);

        $response = $this->actingAs($admin)->get(route('admin.attendance.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('href="'.e(route('admin.attendance.export', ['status' => 'active'])).'"', false)
            ->assertSee('<option value="active" selected>Checked in</option>', false)
            ->assertSee('No attendance sessions match these filters.');

        $this->assertStringNotContainsString('data-attendance-row', $response->getContent());
    }

    public function test_participant_attendance_page_query_count_does_not_grow_with_sessions(): void
    {
        $this->assertSame($this->participantPageQueryCount(1), $this->participantPageQueryCount(3));
    }

    public function test_attendance_management_page_query_count_does_not_grow_with_sessions(): void
    {
        $this->assertSame($this->managePageQueryCount(1), $this->managePageQueryCount(3));
    }

    private function participantPageQueryCount(int $sessions): int
    {
        $participant = User::factory()->create();
        foreach (range(1, $sessions) as $day) {
            $this->attendanceSession($participant, "2026-09-0{$day} 09:00:00", "2026-09-0{$day} 10:00:00");
        }

        return $this->queryCount($participant, route('participant.attendance.index'), $sessions);
    }

    private function managePageQueryCount(int $participants): int
    {
        AttendanceSession::query()->delete();
        $coach = User::factory()->create(['role' => 'coach']);
        foreach (range(1, $participants) as $day) {
            $this->attendanceSession(User::factory()->create(), "2026-09-0{$day} 09:00:00", "2026-09-0{$day} 10:00:00");
        }

        return $this->queryCount($coach, route('coach.attendance.index'), $participants);
    }

    private function queryCount(User $user, string $url, int $expectedRows): int
    {
        $this->actingAs($user)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($user)->get($url)->assertOk();
        DB::disableQueryLog();
        $this->assertSame($expectedRows, substr_count($response->getContent(), 'data-attendance-row'));

        return count(DB::getQueryLog());
    }

    private function attendanceSession(User $participant, string $checkIn, ?string $checkOut = null): AttendanceSession
    {
        $checkInAt = Carbon::parse($checkIn, config('app.timezone'));
        $checkOutAt = $checkOut ? Carbon::parse($checkOut, config('app.timezone')) : null;

        return AttendanceSession::query()->create([
            'user_id' => $participant->id,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'total_seconds' => $checkOutAt ? (int) $checkInAt->diffInSeconds($checkOutAt) : null,
        ]);
    }
}
