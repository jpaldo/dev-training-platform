<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklySessionAttendance;
use App\Services\MentorAssignmentService;
use App\Services\ProgramEnrollmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklySessionAttendancePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_grid_lists_every_participant_by_name_with_active_week_columns_dates_and_totals(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $program = Program::factory()->create(['name' => 'Backend Track']);
        $weekOne = $this->week($program, 1, '2026-11-09');
        $weekTwo = $this->week($program, 2, '2026-11-16');
        $weekThree = $this->week($program, 3, null);
        $this->week($program, 4, null);
        $bea = User::factory()->create(['name' => 'Bea Zane', 'employee_id' => 'EMP-2']);
        $ada = User::factory()->create(['name' => 'Ada Young', 'employee_id' => 'EMP-1']);
        $cal = User::factory()->create(['name' => 'Cal Xu', 'employee_id' => null]);
        $memberCoach = User::factory()->create(['role' => UserRole::Coach, 'name' => 'Dee Coach']);
        $this->mentor($program, User::factory()->create(['role' => UserRole::Coach]), $bea, $ada);
        $program->participants()->attach([$cal->id => ['joined_at' => now()], $memberCoach->id => ['joined_at' => now()]]);
        $this->attend($ada, $weekOne);
        $this->attend($bea, $weekTwo);
        $this->attend($bea, $weekThree);
        $this->attend($ada, TrainingWeek::factory()->create());

        $response = $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN · ATTENDANCE',
                '>Attendance</h1>',
                'Record who joined each week\'s session of a curriculum.',
                'id="attendance-main-action"',
                '<a href="'.route('admin.attendance.export', ['program' => $program->id]).'"',
                '>Export CSV</a>',
                'aria-label="Choose curriculum"',
                '<option value="'.$program->id.'" selected>Backend Track (9 – 22 Nov 2026)</option>',
                '>Show</button>',
                '>Backend Track</h2>',
                'role="region"',
                '>Participant</th>',
                '>Week 1</span>',
                '>9 – 15 Nov 2026</span>',
                '>Week 2</span>',
                '>16 – 22 Nov 2026</span>',
                '>Week 3</span>',
                '>Not scheduled</span>',
                '>Week 4</span>',
                '>Not scheduled</span>',
                '>Total</th>',
                '>Ada Young</strong>',
                '>EMP-1</span>',
                '>1 of 4</td>',
                '>Bea Zane</strong>',
                '>EMP-2</span>',
                '>2 of 4</td>',
                '>Cal Xu</strong>',
                '>0 of 4</td>',
                '>Save attendance</button>',
            ], false)
            ->assertSee('<form method="POST" action="'.route('admin.attendance.update').'"', false)
            ->assertSee('<input type="hidden" name="_method" value="PUT">', false)
            ->assertSee('<input type="hidden" name="program" value="'.$program->id.'">', false)
            ->assertSee('<input type="hidden" name="attendance['.$ada->id.']['.$weekOne->id.']" value="0">', false)
            ->assertDontSee('Dee Coach')
            ->assertDontSee('Participant Attendance')
            ->assertDontSee('name="date_from"', false);

        $this->assertDoesNotMatchRegularExpression('/check.?in|check.?out|training hours/i', $response->getContent());
        $this->assertSame([
            'Ada Young attended Week 1' => true,
            'Ada Young attended Week 2' => false,
            'Ada Young attended Week 3' => false,
            'Ada Young attended Week 4' => false,
            'Bea Zane attended Week 1' => false,
            'Bea Zane attended Week 2' => true,
            'Bea Zane attended Week 3' => true,
            'Bea Zane attended Week 4' => false,
            'Cal Xu attended Week 1' => false,
            'Cal Xu attended Week 2' => false,
            'Cal Xu attended Week 3' => false,
            'Cal Xu attended Week 4' => false,
        ], $this->checkboxes($response));
    }

    public function test_page_opens_on_the_first_curriculum_of_the_list_and_shows_another_when_chosen(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $older = Program::factory()->withWeeks(2, Carbon::parse('2026-09-07'))->create(['name' => 'Older Track']);
        $newer = Program::factory()->withWeeks(2, Carbon::parse('2026-11-09'))->create(['name' => 'Newer Track']);
        $older->participants()->attach(User::factory()->create(['name' => 'Bo Older'])->id, ['joined_at' => now()]);
        $newer->participants()->attach(User::factory()->create(['name' => 'Ann Newer'])->id, ['joined_at' => now()]);

        $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSee('<option value="'.$newer->id.'" selected>', false)
            ->assertSee('<option value="'.$older->id.'" >', false)
            ->assertSee('Ann Newer')
            ->assertDontSee('Bo Older');

        $this->actingAs($admin)->get(route('admin.attendance.index', ['program' => $older->id]))
            ->assertOk()
            ->assertSee('<option value="'.$older->id.'" selected>', false)
            ->assertSee('<option value="'.$newer->id.'" >', false)
            ->assertSee('Bo Older')
            ->assertDontSee('Ann Newer');
    }

    public function test_mentor_sees_only_the_curricula_they_mentor_and_only_their_current_mentees(): void
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withWeeks(2)->create(['name' => 'Mentored Track']);
        Program::factory()->withWeeks(2)->create(['name' => 'Other Track']);
        $mine = User::factory()->create(['name' => 'Mia Mine']);
        $theirs = User::factory()->create(['name' => 'Oli Other']);
        $unassigned = User::factory()->create(['name' => 'Una Unassigned']);
        $this->mentor($program, $coach, $mine);
        $this->mentor($program, User::factory()->create(['role' => UserRole::Coach]), $theirs);
        $program->participants()->attach($unassigned->id, ['joined_at' => now()]);

        $this->actingAs($coach)->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('MENTOR · ATTENDANCE')
            ->assertSee('<a href="'.route('coach.attendance.export', ['program' => $program->id]).'"', false)
            ->assertSee('<option value="'.$program->id.'" selected>', false)
            ->assertDontSee('Other Track')
            ->assertSee('Mia Mine')
            ->assertDontSee('Oli Other')
            ->assertDontSee('Una Unassigned');
    }

    public function test_after_a_mentor_change_only_the_new_mentor_sees_the_participant_with_the_earlier_marks(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $previousMentor = User::factory()->create(['role' => UserRole::Coach]);
        $newMentor = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withMentors($newMentor)->create();
        $week = $this->week($program, 1, '2026-11-09');
        $this->week($program, 2, '2026-11-16');
        $participant = User::factory()->create(['name' => 'Ada Young']);
        $this->mentor($program, $previousMentor, $participant);
        $this->attend($participant, $week, $previousMentor);

        app(MentorAssignmentService::class)->assign($program, $participant, $newMentor, $admin);

        $this->actingAs($previousMentor)->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('No participants to show.')
            ->assertDontSee('Ada Young');
        $response = $this->actingAs($newMentor)->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('>1 of 2</td>', false);
        $this->assertSame([
            'Ada Young attended Week 1' => true,
            'Ada Young attended Week 2' => false,
        ], $this->checkboxes($response));
    }

    public function test_a_removed_participant_disappears_and_shows_the_earlier_marks_when_added_again(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $program = Program::factory()->create();
        $week = $this->week($program, 1, '2026-11-09');
        $this->week($program, 2, '2026-11-16');
        $participant = User::factory()->create(['name' => 'Ada Young']);
        $program->participants()->attach($participant->id, ['joined_at' => now()]);
        $this->attend($participant, $week);
        $enrollment = app(ProgramEnrollmentService::class);

        $enrollment->removeParticipant($program, $participant, $admin);

        $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSee('No participants to show.')
            ->assertDontSee('Ada Young');

        $enrollment->addParticipants($program, [$participant->id], $admin);

        $response = $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSee('>1 of 2</td>', false);
        $this->assertSame([
            'Ada Young attended Week 1' => true,
            'Ada Young attended Week 2' => false,
        ], $this->checkboxes($response));
    }

    public function test_inactive_weeks_and_their_attendance_are_not_shown(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1, '2026-11-09');
        $inactiveWeek = $this->week($program, 2, '2026-11-16', isActive: false);
        $this->week($program, 3, '2026-11-23');
        $participant = User::factory()->create(['name' => 'Ada Young']);
        $program->participants()->attach($participant->id, ['joined_at' => now()]);
        $this->attend($participant, $weekOne);
        $this->attend($participant, $inactiveWeek);

        $response = $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Week 1</span>', '>Week 3</span>', '>1 of 2</td>'], false)
            ->assertDontSee('Week 2')
            ->assertDontSee('16 – 22 Nov 2026');
        $this->assertSame([
            'Ada Young attended Week 1' => true,
            'Ada Young attended Week 3' => false,
        ], $this->checkboxes($response));
    }

    public function test_mentor_of_no_curriculum_sees_no_curricula_and_no_picker(): void
    {
        Program::factory()->withWeeks(2)->create(['name' => 'Backend Track']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Coach]))->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('No curricula to show.')
            ->assertDontSee('aria-label="Choose curriculum"', false)
            ->assertDontSee('Backend Track')
            ->assertDontSee('<table', false)
            ->assertDontSee('Save attendance')
            ->assertDontSee('Export CSV');
    }

    public function test_curriculum_without_active_weeks_says_it_has_no_weeks(): void
    {
        $program = Program::factory()->create(['name' => 'Backend Track']);
        $this->week($program, 1, '2026-11-09', isActive: false);
        $program->participants()->attach(User::factory()->create(['name' => 'Ada Young'])->id, ['joined_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Backend Track</h2>', 'This curriculum has no weeks.'], false)
            ->assertDontSee('Ada Young')
            ->assertDontSee('<table', false)
            ->assertDontSee('Save attendance');
    }

    public function test_curriculum_without_participants_says_there_are_none_to_show(): void
    {
        Program::factory()->withWeeks(2)->create(['name' => 'Backend Track']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Export CSV</a>', '>Backend Track</h2>', 'No participants to show.'], false)
            ->assertDontSee('Sessions attended')
            ->assertDontSee('<table', false)
            ->assertDontSee('Save attendance');
    }

    /**
     * @return array<string, array{0: UserRole, 1: string, 2: string}>
     */
    public static function invalidProgramChoices(): array
    {
        return [
            'a curriculum the mentor does not mentor' => [UserRole::Coach, 'coach.attendance.index', 'not mentored'],
            'an unknown curriculum, mentor' => [UserRole::Coach, 'coach.attendance.index', 'unknown'],
            'an unknown curriculum, admin' => [UserRole::Admin, 'admin.attendance.index', 'unknown'],
            'not a number' => [UserRole::Coach, 'coach.attendance.index', 'not a number'],
        ];
    }

    #[DataProvider('invalidProgramChoices')]
    public function test_an_invalid_curriculum_choice_returns_to_the_page_of_the_area_with_an_error(UserRole $role, string $routeName, string $choice): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $mentored = Program::factory()->withMentors($viewer)->withWeeks(1)->create(['name' => 'Mentored Track']);
        $notMentored = Program::factory()->withWeeks(1)->create(['name' => 'Other Track']);
        $program = match ($choice) {
            'not mentored' => $notMentored->id,
            'unknown' => $notMentored->id + 1000,
            'not a number' => 'abc',
        };

        $this->actingAs($viewer)->get(route($routeName, ['program' => $program]))
            ->assertRedirect(route($routeName))
            ->assertSessionHasErrors(['program' => 'Choose a curriculum from the list.']);

        $this->actingAs($viewer)->followingRedirects()->get(route($routeName, ['program' => $program]))
            ->assertOk()
            ->assertSeeInOrder(['role="alert"', 'Choose a curriculum from the list.', '<option value="'.$mentored->id.'" selected>'], false);
    }

    public function test_participant_is_forbidden_from_both_attendance_pages(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get(route('coach.attendance.index'))->assertForbidden();
        $this->actingAs($participant)->get(route('admin.attendance.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_both_attendance_pages(): void
    {
        $this->get(route('coach.attendance.index'))->assertRedirect(route('login'));
        $this->get(route('admin.attendance.index'))->assertRedirect(route('login'));
    }

    public function test_page_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->pageQueryCount(1), $this->pageQueryCount(3));
    }

    public function test_saving_the_grid_keeps_exactly_the_ticked_cells_and_returns_to_the_curriculum_with_a_flash(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 09:00:00'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $firstMarker = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1, '2026-11-09');
        $weekTwo = $this->week($program, 2, '2026-11-16');
        $ada = User::factory()->create(['name' => 'Ada Young']);
        $bea = User::factory()->create(['name' => 'Bea Zane']);
        $program->participants()->attach([$ada->id => ['joined_at' => now()], $bea->id => ['joined_at' => now()]]);
        $this->attend($ada, $weekOne, $firstMarker);
        $this->attend($bea, $weekTwo, $firstMarker);
        $this->travelTo(Carbon::parse('2026-11-18 10:30:00'));

        $this->actingAs($admin)->put(route('admin.attendance.update'), [
            'program' => $program->id,
            'attendance' => [
                $ada->id => [$weekOne->id => '0', $weekTwo->id => '1'],
                $bea->id => [$weekOne->id => '1', $weekTwo->id => '1'],
            ],
        ])
            ->assertRedirect(route('admin.attendance.index', ['program' => $program->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Attendance saved.');

        $this->assertSame([
            [$ada->id, $weekTwo->id, $admin->id, '2026-11-18 10:30:00'],
            [$bea->id, $weekOne->id, $admin->id, '2026-11-18 10:30:00'],
            [$bea->id, $weekTwo->id, $firstMarker->id, '2026-11-11 09:00:00'],
        ], $this->attendanceRecords());
        $this->actingAs($admin)->get(route('admin.attendance.index', ['program' => $program->id]))
            ->assertOk()
            ->assertSeeInOrder(['role="status"', 'Attendance saved.', '>Ada Young</strong>'], false);
    }

    public function test_saving_the_same_grid_again_changes_no_row(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 10:30:00'));
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1, '2026-11-09');
        $weekTwo = $this->week($program, 2, '2026-11-16');
        $participant = User::factory()->create();
        $this->mentor($program, $coach, $participant);
        $grid = ['program' => $program->id, 'attendance' => [$participant->id => [$weekOne->id => '1', $weekTwo->id => '0']]];
        $this->actingAs($coach)->put(route('coach.attendance.update'), $grid)->assertSessionHasNoErrors();
        $saved = WeeklySessionAttendance::query()->orderBy('id')->get()->toArray();
        $this->travel(2)->hours();

        $this->actingAs($coach)->put(route('coach.attendance.update'), $grid)
            ->assertRedirect(route('coach.attendance.index', ['program' => $program->id]))
            ->assertSessionHas('status', 'Attendance saved.');

        $this->assertCount(1, $saved);
        $this->assertSame($saved, WeeklySessionAttendance::query()->orderBy('id')->get()->toArray());
    }

    public function test_a_week_of_a_curriculum_that_has_ended_can_still_be_changed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 10:30:00'));
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1, '2026-09-07');
        $weekTwo = $this->week($program, 2, '2026-09-14');
        $participant = User::factory()->create();
        $this->mentor($program, $coach, $participant);
        $this->attend($participant, $weekOne, $coach);

        $this->actingAs($coach)->put(route('coach.attendance.update'), [
            'program' => $program->id,
            'attendance' => [$participant->id => [$weekOne->id => '0', $weekTwo->id => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame([[$participant->id, $weekTwo->id, $coach->id, '2026-11-18 10:30:00']], $this->attendanceRecords());
    }

    public function test_a_mentor_can_tick_a_week_that_has_not_started_and_an_unscheduled_week(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 10:30:00'));
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->create();
        $futureWeek = $this->week($program, 1, '2026-11-09');
        $unscheduledWeek = $this->week($program, 2, null);
        $participant = User::factory()->create();
        $this->mentor($program, $coach, $participant);

        $this->actingAs($coach)->put(route('coach.attendance.update'), [
            'program' => $program->id,
            'attendance' => [$participant->id => [$futureWeek->id => '1', $unscheduledWeek->id => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            [$participant->id, $futureWeek->id, $coach->id, '2026-11-01 10:30:00'],
            [$participant->id, $unscheduledWeek->id, $coach->id, '2026-11-01 10:30:00'],
        ], $this->attendanceRecords());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cellsOutsideTheMentorsScope(): array
    {
        return [
            'another mentor\'s mentee' => ['other mentee', 'You can only record attendance for participants shown in this curriculum.'],
            'an unassigned participant' => ['unassigned participant', 'You can only record attendance for participants shown in this curriculum.'],
            'a week of another curriculum' => ['other curriculum week', 'You can only record attendance for active weeks of this curriculum.'],
            'an inactive week' => ['inactive week', 'You can only record attendance for active weeks of this curriculum.'],
        ];
    }

    #[DataProvider('cellsOutsideTheMentorsScope')]
    public function test_a_mentor_save_with_a_cell_outside_their_scope_is_refused_and_changes_nothing(string $cell, string $message): void
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->create();
        $week = $this->week($program, 1, '2026-11-09');
        $inactiveWeek = $this->week($program, 2, '2026-11-16', isActive: false);
        $otherCurriculumWeek = TrainingWeek::factory()->create();
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $unassigned = User::factory()->create();
        $this->mentor($program, $coach, $mine);
        $this->mentor($program, User::factory()->create(['role' => UserRole::Coach]), $theirs);
        $program->participants()->attach($unassigned->id, ['joined_at' => now()]);
        $this->attend($mine, $week);
        $before = $this->attendanceRecords();
        [$participant, $weekId] = match ($cell) {
            'other mentee' => [$theirs, $week->id],
            'unassigned participant' => [$unassigned, $week->id],
            'other curriculum week' => [$mine, $otherCurriculumWeek->id],
            'inactive week' => [$mine, $inactiveWeek->id],
        };
        $attendance = [$mine->id => [$week->id => '0']];
        $attendance[$participant->id][$weekId] = '1';

        $this->actingAs($coach)->put(route('coach.attendance.update'), ['program' => $program->id, 'attendance' => $attendance])
            ->assertRedirect(route('coach.attendance.index', ['program' => $program->id]))
            ->assertSessionHasErrors(['attendance' => $message])
            ->assertSessionMissing('status');

        $this->assertSame($before, $this->attendanceRecords());
    }

    public function test_a_mentor_saving_a_curriculum_they_do_not_mentor_is_refused_on_the_curriculum(): void
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        Program::factory()->withMentors($coach)->withWeeks(1)->create();
        $otherProgram = Program::factory()->create();
        $week = $this->week($otherProgram, 1, '2026-11-09');
        $participant = User::factory()->create();
        $this->mentor($otherProgram, User::factory()->create(['role' => UserRole::Coach]), $participant);
        $this->attend($participant, $week);
        $before = $this->attendanceRecords();

        $this->actingAs($coach)->put(route('coach.attendance.update'), [
            'program' => $otherProgram->id,
            'attendance' => [$participant->id => [$week->id => '0']],
        ])
            ->assertRedirect(route('coach.attendance.index', ['program' => $otherProgram->id]))
            ->assertSessionHasErrors(['program' => 'Choose a curriculum from the list.'])
            ->assertSessionDoesntHaveErrors('attendance');

        $this->assertSame($before, $this->attendanceRecords());
    }

    public function test_a_cell_posting_anything_but_0_or_1_is_refused_and_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1, '2026-11-09');
        $weekTwo = $this->week($program, 2, '2026-11-16');
        $participant = User::factory()->create();
        $program->participants()->attach($participant->id, ['joined_at' => now()]);
        $this->attend($participant, $weekOne);
        $before = $this->attendanceRecords();

        $this->actingAs($admin)->put(route('admin.attendance.update'), [
            'program' => $program->id,
            'attendance' => [$participant->id => [$weekOne->id => '0', $weekTwo->id => 'yes']],
        ])
            ->assertRedirect(route('admin.attendance.index', ['program' => $program->id]))
            ->assertSessionHasErrors(["attendance.{$participant->id}.{$weekTwo->id}" => 'The selected attendance is invalid.']);

        $this->assertSame($before, $this->attendanceRecords());
    }

    public function test_the_new_mentor_can_untick_a_week_the_previous_mentor_marked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $previousMentor = User::factory()->create(['role' => UserRole::Coach]);
        $newMentor = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withMentors($newMentor)->create();
        $week = $this->week($program, 1, '2026-11-09');
        $participant = User::factory()->create();
        $this->mentor($program, $previousMentor, $participant);
        $this->attend($participant, $week, $previousMentor);
        app(MentorAssignmentService::class)->assign($program, $participant, $newMentor, $admin);

        $this->actingAs($newMentor)->put(route('coach.attendance.update'), [
            'program' => $program->id,
            'attendance' => [$participant->id => [$week->id => '0']],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $this->attendanceRecords());
    }

    public function test_participant_is_forbidden_from_saving_attendance_in_either_area(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $week = $this->week($program, 1, '2026-11-09');
        $program->participants()->attach($participant->id, ['joined_at' => now()]);
        $grid = ['program' => $program->id, 'attendance' => [$participant->id => [$week->id => '1']]];

        $this->actingAs($participant)->put(route('coach.attendance.update'), $grid)->assertForbidden();
        $this->actingAs($participant)->put(route('admin.attendance.update'), $grid)->assertForbidden();

        $this->assertSame([], $this->attendanceRecords());
    }

    public function test_guest_is_redirected_to_login_when_saving_attendance(): void
    {
        $this->put(route('coach.attendance.update'))->assertRedirect(route('login'));
        $this->put(route('admin.attendance.update'))->assertRedirect(route('login'));
    }

    public function test_save_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->saveQueryCount(1), $this->saveQueryCount(3));
    }

    public function test_summary_sums_attended_of_held_sessions_counting_a_ticked_week_not_yet_started_as_held(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$program] = $this->twoParticipantsOverFourWeeks();

        $this->actingAs($admin)->get(route('admin.attendance.index', ['program' => $program->id]))
            ->assertOk()
            ->assertSeeInOrder(['>Backend Track</h2>', '>Sessions attended</span>', '>4 of 5 · 80%</dd>', 'role="region"', '>3 of 4</td>', '>1 of 4</td>'], false);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function summaryRates(): array
    {
        return [
            'a half rounds up' => [8, 5, '5 of 8 · 63%'],
            'two thirds round up' => [3, 2, '2 of 3 · 67%'],
            'one third rounds down' => [3, 1, '1 of 3 · 33%'],
        ];
    }

    #[DataProvider('summaryRates')]
    public function test_summary_and_csv_rate_round_half_up_to_a_whole_percent(int $startedWeeks, int $attendedWeeks, string $summary): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $program = Program::factory()->create();
        $participant = User::factory()->create();
        $program->participants()->attach($participant->id, ['joined_at' => now()]);
        foreach (range(1, $startedWeeks) as $number) {
            $week = $this->week($program, $number, Carbon::parse('2026-11-09')->subWeeks($startedWeeks - $number)->toDateString());
            if ($number <= $attendedWeeks) {
                $this->attend($participant, $week);
            }
        }

        $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSee(">{$summary}</dd>", false);
        $rows = $this->csv($this->actingAs($admin)->get(route('admin.attendance.export')));

        $this->assertSame(substr($summary, -3, 2), $rows[1][count($rows[1]) - 1]);
    }

    public function test_without_a_started_week_or_a_tick_the_summary_says_no_sessions_were_held_and_the_csv_rate_is_empty(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $program = Program::factory()->create();
        $this->week($program, 1, '2026-11-09');
        $this->week($program, 2, null);
        $participant = User::factory()->create(['name' => 'Ada Young', 'employee_id' => 'EMP-1', 'email' => 'ada@example.com']);
        $program->participants()->attach($participant->id, ['joined_at' => now()]);

        $this->actingAs($admin)->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Sessions attended</span>', '>No sessions held yet</dd>'], false);

        $this->assertSame([
            ['Participant', 'Employee ID', 'Email', 'Mentor', 'Week 1', 'Week 2', 'Attended', 'Held', 'Rate (%)'],
            ['Ada Young', 'EMP-1', 'ada@example.com', 'Not assigned', 'Not held', 'Not held', '0', '0', ''],
        ], $this->csv($this->actingAs($admin)->get(route('admin.attendance.export'))));
    }

    public function test_admin_csv_of_the_first_curriculum_marks_each_active_week_and_lists_unassigned_participants(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $older = Program::factory()->withWeeks(1, Carbon::parse('2026-09-07'))->create();
        $older->participants()->attach(User::factory()->create(['name' => 'Bo Older'])->id, ['joined_at' => now()]);
        $this->twoParticipantsOverFourWeeks();

        $this->assertSame([
            ['Participant', 'Employee ID', 'Email', 'Mentor', 'Week 1', 'Week 2', 'Week 3', 'Week 4', 'Attended', 'Held', 'Rate (%)'],
            ['Ada Young', 'EMP-1', 'ada@example.com', 'Mo Mentor', 'Attended', 'Attended', 'Attended', 'Not held', '3', '3', '100'],
            ['Bea Zane', '', 'bea@example.com', 'Not assigned', 'Attended', 'Absent', 'Not held', 'Not held', '1', '2', '50'],
        ], $this->csv($this->actingAs($admin)->get(route('admin.attendance.export'))));
    }

    public function test_mentor_csv_of_the_chosen_curriculum_lists_only_their_mentees_by_name_with_a_column_per_active_week(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        $coach = User::factory()->create(['role' => UserRole::Coach, 'name' => 'Mo Mentor']);
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1, '2026-11-02');
        $inactiveWeek = $this->week($program, 2, '2026-11-09', isActive: false);
        $this->week($program, 3, '2026-11-16');
        Program::factory()->withMentors($coach)->withWeeks(1, Carbon::parse('2026-12-07'))->create();
        $zoe = User::factory()->create(['name' => 'Zoe Young', 'employee_id' => 'EMP-9', 'email' => 'zoe@example.com']);
        $ada = User::factory()->create(['name' => 'Ada Xu', 'employee_id' => 'EMP-1', 'email' => 'ada@example.com']);
        $this->mentor($program, $coach, $zoe, $ada);
        $this->mentor($program, User::factory()->create(['role' => UserRole::Coach]), User::factory()->create(['name' => 'Oli Other']));
        $program->participants()->attach(User::factory()->create(['name' => 'Una Unassigned'])->id, ['joined_at' => now()]);
        $this->attend($zoe, $weekOne);
        $this->attend($zoe, $inactiveWeek);

        $this->assertSame([
            ['Participant', 'Employee ID', 'Email', 'Mentor', 'Week 1', 'Week 3', 'Attended', 'Held', 'Rate (%)'],
            ['Ada Xu', 'EMP-1', 'ada@example.com', 'Mo Mentor', 'Absent', 'Not held', '0', '1', '0'],
            ['Zoe Young', 'EMP-9', 'zoe@example.com', 'Mo Mentor', 'Attended', 'Not held', '1', '1', '100'],
        ], $this->csv($this->actingAs($coach)->get(route('coach.attendance.export', ['program' => $program->id]))));
    }

    public function test_csv_without_any_curriculum_has_only_the_header_row(): void
    {
        Program::factory()->withWeeks(1)->create();

        $this->assertSame(
            [['Participant', 'Employee ID', 'Email', 'Mentor', 'Attended', 'Held', 'Rate (%)']],
            $this->csv($this->actingAs(User::factory()->create(['role' => UserRole::Coach]))->get(route('coach.attendance.export'))),
        );
    }

    /**
     * @return array<string, array{0: UserRole, 1: string, 2: string}>
     */
    public static function invalidExportChoices(): array
    {
        return [
            'a curriculum the mentor does not mentor' => [UserRole::Coach, 'coach.attendance', 'not mentored'],
            'an unknown curriculum, admin' => [UserRole::Admin, 'admin.attendance', 'unknown'],
        ];
    }

    #[DataProvider('invalidExportChoices')]
    public function test_an_invalid_curriculum_choice_on_the_export_returns_to_the_page_of_the_area_with_an_error(UserRole $role, string $routePrefix, string $choice): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $notMentored = Program::factory()->withWeeks(1)->create();
        $program = $choice === 'unknown' ? $notMentored->id + 1000 : $notMentored->id;

        $this->actingAs($viewer)->get(route("{$routePrefix}.export", ['program' => $program]))
            ->assertRedirect(route("{$routePrefix}.index"))
            ->assertSessionHasErrors(['program' => 'Choose a curriculum from the list.']);
    }

    public function test_participant_is_forbidden_from_the_export_in_either_area(): void
    {
        $participant = User::factory()->create();
        Program::factory()->withWeeks(1)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('coach.attendance.export'))->assertForbidden();
        $this->actingAs($participant)->get(route('admin.attendance.export'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_the_export(): void
    {
        $this->get(route('coach.attendance.export'))->assertRedirect(route('login'));
        $this->get(route('admin.attendance.export'))->assertRedirect(route('login'));
    }

    public function test_export_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->exportQueryCount(1), $this->exportQueryCount(3));
    }

    /**
     * "Backend Track" with four active weeks from 2 Nov 2026 (two started on 10 Nov): Ada Young (EMP-1, mentored by Mo
     * Mentor) attended Weeks 1–3 and Bea Zane (no employee ID, no mentor) Week 1.
     *
     * @return array{0: Program}
     */
    private function twoParticipantsOverFourWeeks(): array
    {
        $program = Program::factory()->create(['name' => 'Backend Track']);
        $weeks = collect(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'])
            ->map(fn (string $startsOn, int $index): TrainingWeek => $this->week($program, $index + 1, $startsOn));
        $ada = User::factory()->create(['name' => 'Ada Young', 'employee_id' => 'EMP-1', 'email' => 'ada@example.com']);
        $bea = User::factory()->create(['name' => 'Bea Zane', 'employee_id' => null, 'email' => 'bea@example.com']);
        $this->mentor($program, User::factory()->create(['role' => UserRole::Coach, 'name' => 'Mo Mentor']), $ada);
        $program->participants()->attach($bea->id, ['joined_at' => now()]);
        $this->attend($ada, $weeks[0]);
        $this->attend($ada, $weeks[1]);
        $this->attend($ada, $weeks[2]);
        $this->attend($bea, $weeks[0]);

        return [$program];
    }

    private function exportQueryCount(int $participants): int
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withWeeks(3)->create();
        $weeks = $program->weeks()->get();
        foreach (range(1, $participants) as $index) {
            $participant = User::factory()->create();
            $this->mentor($program, $coach, $participant);
            $this->attend($participant, $weeks[$index - 1]);
        }
        $url = route('coach.attendance.export', ['program' => $program->id]);

        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->csv($this->actingAs($coach)->get($url));
        DB::disableQueryLog();
        $this->assertCount($participants + 1, $rows);

        return count(DB::getQueryLog());
    }

    private function saveQueryCount(int $participants): int
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withWeeks(3)->create();
        $weeks = $program->weeks()->orderBy('week_number')->get();
        $attendance = [];
        foreach (range(1, $participants) as $index) {
            $participant = User::factory()->create();
            $this->mentor($program, $coach, $participant);
            $this->attend($participant, $weeks[0]);
            $attendance[$participant->id] = [$weeks[0]->id => '0', $weeks[1]->id => '1', $weeks[2]->id => '0'];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->put(route('coach.attendance.update'), ['program' => $program->id, 'attendance' => $attendance])
            ->assertSessionHasNoErrors();
        DB::disableQueryLog();
        $this->assertSame($participants, WeeklySessionAttendance::query()->where('training_week_id', $weeks[1]->id)->count());

        return count(DB::getQueryLog());
    }

    private function pageQueryCount(int $participants): int
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        $program = Program::factory()->withWeeks(3)->create();
        $weeks = $program->weeks()->get();
        foreach (range(1, $participants) as $index) {
            $participant = User::factory()->create();
            $this->mentor($program, $coach, $participant);
            $this->attend($participant, $weeks[$index - 1]);
        }
        $url = route('coach.attendance.index', ['program' => $program->id]);

        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($coach)->get($url)->assertOk();
        DB::disableQueryLog();
        $this->assertCount($participants * 3, $this->checkboxes($response));

        return count(DB::getQueryLog());
    }

    /**
     * An active week of the curriculum, dated for seven days from `$startsOn`, or unscheduled without it.
     */
    private function week(Program $program, int $number, ?string $startsOn, bool $isActive = true): TrainingWeek
    {
        $week = TrainingWeek::factory()->for($program);

        return ($startsOn === null ? $week->unscheduled() : $week->opensOn(Carbon::parse($startsOn)))
            ->create(['week_number' => $number, 'position' => $number, 'is_active' => $isActive]);
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
     * Record that the participant attended the week's session, marked by the given mentor or a new one.
     */
    private function attend(User $participant, TrainingWeek $week, ?User $markedBy = null): void
    {
        WeeklySessionAttendance::factory()
            ->for($week, 'week')
            ->for($participant, 'participant')
            ->create($markedBy === null ? [] : ['marked_by' => $markedBy->id]);
    }

    /**
     * Every attendance record as [participant id, week id, marker id, marked at], by participant and then week.
     *
     * @return list<array{0: int, 1: int, 2: int|null, 3: string}>
     */
    private function attendanceRecords(): array
    {
        return WeeklySessionAttendance::query()
            ->orderBy('user_id')
            ->orderBy('training_week_id')
            ->get()
            ->map(fn (WeeklySessionAttendance $record): array => [$record->user_id, $record->training_week_id, $record->marked_by, $record->marked_at->toDateTimeString()])
            ->all();
    }

    /**
     * Whether each checkbox of the grid is ticked, by its label, in page order. Every checkbox must be enabled, post 1
     * and follow a hidden input of the same name posting 0.
     *
     * @return array<string, bool>
     */
    private function checkboxes(TestResponse $response): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="0">\s*<input\s+type="checkbox"\s+name="\1"\s+value="1"\s+aria-label="([^"]+)"\s+(checked\s+)?class="/', $response->getContent(), $matches, PREG_SET_ORDER);
        $this->assertSame(substr_count($response->getContent(), 'type="checkbox"'), count($matches));
        $this->assertDoesNotMatchRegularExpression('/type="checkbox"[^>]*\sdisabled/', $response->getContent());

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [html_entity_decode($match[2], ENT_QUOTES) => ($match[3] ?? '') !== ''])
            ->all();
    }

    /**
     * The rows of the downloaded weekly session attendance CSV.
     *
     * @return list<list<string>>
     */
    private function csv(TestResponse $response): array
    {
        $response->assertOk()->assertDownload('weekly-session-attendance.csv');

        return array_map(str_getcsv(...), explode("\n", trim($response->streamedContent())));
    }
}
