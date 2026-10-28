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
            ], false)
            ->assertDontSee('Dee Coach')
            ->assertDontSee('Participant Attendance')
            ->assertDontSee('Export CSV')
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
        ], $this->disabledCheckboxes($response));
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
        ], $this->disabledCheckboxes($response));
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
        ], $this->disabledCheckboxes($response));
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
        ], $this->disabledCheckboxes($response));
    }

    public function test_mentor_of_no_curriculum_sees_no_curricula_and_no_picker(): void
    {
        Program::factory()->withWeeks(2)->create(['name' => 'Backend Track']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Coach]))->get(route('coach.attendance.index'))
            ->assertOk()
            ->assertSee('No curricula to show.')
            ->assertDontSee('aria-label="Choose curriculum"', false)
            ->assertDontSee('Backend Track')
            ->assertDontSee('<table', false);
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
            ->assertDontSee('<table', false);
    }

    public function test_curriculum_without_participants_says_there_are_none_to_show(): void
    {
        Program::factory()->withWeeks(2)->create(['name' => 'Backend Track']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Backend Track</h2>', 'No participants to show.'], false)
            ->assertDontSee('<table', false);
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
        $this->assertCount($participants * 3, $this->disabledCheckboxes($response));

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
     * Whether each checkbox of the grid is ticked, by its label, in page order. Every checkbox must be disabled.
     *
     * @return array<string, bool>
     */
    private function disabledCheckboxes(TestResponse $response): array
    {
        preg_match_all('/<input\s+type="checkbox"\s+aria-label="([^"]+)"\s+(checked\s+)?disabled\s/', $response->getContent(), $matches, PREG_SET_ORDER);
        $this->assertSame(substr_count($response->getContent(), 'type="checkbox"'), count($matches));

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [html_entity_decode($match[1], ENT_QUOTES) => ($match[2] ?? '') !== ''])
            ->all();
    }
}
