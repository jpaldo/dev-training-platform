<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AttendanceSession;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendancePagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_attendance_page_shows_filters_sessions_and_an_export_main_action_for_the_same_query(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['name' => 'Ana Manual']);
        $automation = User::factory()->create(['name' => 'Ben Automation']);
        $this->mentor(Program::factory()->create(), $coach, $manual, $automation);
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

    public function test_a_mentor_sees_and_exports_only_the_attendance_of_their_current_mentees(): void
    {
        $programX = Program::factory()->create();
        $programY = Program::factory()->create();
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        [$ana, $ben, $cy] = User::factory()->count(3)->create(['role' => 'coach'])->all();
        $this->mentor($programX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($programX, $ben, $pat);
        $this->mentor($programY, $cy, $quinn);
        $this->attendanceSession($pat, '2026-09-09 09:00:00', '2026-09-09 11:00:00');
        $this->attendanceSession($quinn, '2026-09-10 09:00:00', '2026-09-10 10:00:00');

        $benPage = $this->actingAs($ben)->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('<option value="'.$pat->id.'" >Pat Participant</option>', false)
            ->assertDontSee('Quinn Other');
        $this->assertSame(1, substr_count($benPage->getContent(), 'data-attendance-row'));

        $this->assertSame([['Pat Participant', '2026-09-09']], $this->exportedRows($ben));
        $this->assertSame([['Quinn Other', '2026-09-10']], $this->exportedRows($cy));
        $this->assertSame([], $this->exportedRows($ana));

        $this->actingAs($ana)->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('No attendance sessions match these filters.')
            ->assertDontSee('Pat Participant');

        $this->assertSame([['Quinn Other', '2026-09-10'], ['Pat Participant', '2026-09-09']], $this->exportedRows(User::factory()->create(['role' => 'admin'])));
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

    public function test_attendance_management_page_query_count_does_not_grow_with_sessions(): void
    {
        $this->assertSame($this->managePageQueryCount(1), $this->managePageQueryCount(3));
    }

    private function managePageQueryCount(int $participants): int
    {
        AttendanceSession::query()->delete();
        $coach = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->create();
        foreach (range(1, $participants) as $day) {
            $participant = User::factory()->create();
            $this->mentor($program, $coach, $participant);
            $this->attendanceSession($participant, "2026-09-0{$day} 09:00:00", "2026-09-0{$day} 10:00:00");
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

    /**
     * Add the coach to the curriculum's mentors and the participants to the curriculum, with the coach as their current
     * mentor there.
     */
    private function mentor(Program $program, User $coach, User ...$participants): void
    {
        $program->mentors()->syncWithoutDetaching([$coach->id]);
        foreach ($participants as $participant) {
            $program->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
            MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * The participant name and date of each row of the attendance CSV the user downloads from their area, newest
     * first.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function exportedRows(User $user): array
    {
        $route = $user->hasAnyRole(UserRole::Admin) ? 'admin.attendance.export' : 'coach.attendance.export';
        $rows = array_map(str_getcsv(...), explode("\n", trim($this->actingAs($user)->get(route($route))->assertOk()->streamedContent())));

        return array_map(fn (array $row): array => [$row[0], $row[3]], array_slice($rows, 1));
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
