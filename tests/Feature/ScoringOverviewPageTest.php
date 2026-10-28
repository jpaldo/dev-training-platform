<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScoringOverviewPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_mentor_sees_one_row_per_mentee_and_curriculum_with_the_scores_and_completion_of_that_curriculum(): void
    {
        $activities = $this->program(name: 'November curriculum');
        $otherActivities = $this->program(name: 'December curriculum');
        $programA = $activities[2][0]->week->program;
        $programB = $otherActivities[2][0]->week->program;
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $mia = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor']);
        $ada = User::factory()->create(['name' => 'Ada Automation', 'email' => 'ada@example.com']);
        $ben = User::factory()->create(['name' => 'Ben Both', 'email' => 'ben@example.com']);
        $this->mentor($programA, $coach, $ada);
        $this->mentor($programB, $mia, $ada);
        $this->join($programA, $ben);
        $this->score($ada, $activities[2][0], 83, 'Proficient');
        $this->score($ada, $otherActivities[3][0], 40, 'Beginning');

        $response = $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk();

        $response->assertSeeInOrder([
            'MENTOR · SCORING',
            '>Scoring overview</h1>',
            'Completion and activity scores are separate measures.',
            'href="'.route('coach.scoring.criteria').'"', '>View Scoring Criteria</a>',
            'href="'.route('coach.scoring.reports').'"', '>Scoring Reports</a>',
            'aria-label="Filter participants"',
            '>Curriculum</label>', '<option value="">All curricula</option>', '>'.$this->optionLabel($programA).'</option>',
            '>Participant</th>', '>Curriculum</th>',
            '>W2</th>', '>W2 Completion</th>', '>W3</th>', '>W3 Completion</th>', '>W4</th>', '>W4 Completion</th>',
            '>Overall</th>', '>Completion</th>', '>Assigned Mentor</th>',
            '>Ada Automation</strong>', 'ada@example.com',
            '>November curriculum</span>', '>'.$programA->dateRangeLabel().'</span>',
            '>83</strong>', 'Proficient', '1 / 2 (50%)',
            '—', '0 / 1 (0%)',
            '—', '0 / 1 (0%)',
            '>83</td>', '1 / 4 (25%)', 'Coach Carter',
            'href="'.route('coach.scoring.participant', [$programA, $ada]).'"', '>View Scoring</a>',
        ], false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-scoring-row'));
        $response->assertDontSee('December curriculum')
            ->assertDontSee('Mia Mentor')
            ->assertDontSee('>Ben Both</strong>', false)
            ->assertDontSee('id="score-mentor"', false)
            ->assertDontSee('name="assigned_to_me"', false)
            ->assertDontSee('from-brand to-brand-deep', false)
            ->assertDontSee('No participants match these filters.');
    }

    public function test_mentors_see_only_their_current_mentees_in_each_curriculum(): void
    {
        ['programX' => $programX, 'programY' => $programY, 'pat' => $pat, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy] = $this->mentorScopeFixture();

        $this->actingAs($ben)->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Pat Participant</strong>', '>Curriculum X</span>', '>83</strong>', 'href="'.route('coach.scoring.participant', [$programX, $pat]).'"'], false)
            ->assertSee('>'.$this->optionLabel($programX).'</option>', false)
            ->assertDontSee('>'.$this->optionLabel($programY).'</option>', false)
            ->assertDontSee('>Curriculum Y</span>', false)
            ->assertDontSee('Quinn Other');

        $this->actingAs($cy)->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Pat Participant</strong>', '>Curriculum Y</span>', '>60</strong>', 'href="'.route('coach.scoring.participant', [$programY, $pat]).'"'], false)
            ->assertDontSee('>Curriculum X</span>', false)
            ->assertDontSee('>83</strong>', false);

        $this->actingAs($ana)->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-scoring-row', false);
    }

    public function test_an_admin_sees_every_membership_and_filters_by_curriculum_and_by_mentor(): void
    {
        ['programY' => $programY, 'ben' => $ben, 'dan' => $dan] = $this->mentorScopeFixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Mentor</label>', '<option value="">All mentors</option>', '>'.$ben->name.'</option>'], false)
            ->assertSeeInOrder([
                '>Pat Participant</strong>', '>Curriculum Y</span>', '>60</strong>',
                '>Pat Participant</strong>', '>Curriculum X</span>', '>83</strong>', $ben->name,
                '>Quinn Other</strong>', '>Curriculum X</span>', $dan->name,
            ], false);

        $this->actingAs($admin)->get(route('coach.scoring.index', ['program' => $programY->id]))
            ->assertOk()
            ->assertSee('<option value="'.$programY->id.'" selected>', false)
            ->assertSee('>Curriculum Y</span>', false)
            ->assertDontSee('>Curriculum X</span>', false);

        $this->actingAs($admin)->get(route('coach.scoring.index', ['mentor' => $dan->id]))
            ->assertOk()
            ->assertSee('<option value="'.$dan->id.'" selected>', false)
            ->assertSee('>Quinn Other</strong>', false)
            ->assertDontSee('>Pat Participant</strong>', false);
    }

    public function test_an_unknown_curriculum_or_a_mentor_who_is_not_a_coach_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.scoring.index', ['program' => 999, 'mentor' => User::factory()->create()->id]))
            ->assertSessionHasErrors(['program', 'mentor']);
    }

    public function test_week_columns_cover_every_listed_curriculum_and_a_curriculum_without_the_week_shows_dashes(): void
    {
        $longProgram = $this->program(name: 'Four weeks')[2][0]->week->program;
        $shortProgram = $this->program([2 => 2], 'Two weeks')[2][0]->week->program;
        $ada = User::factory()->create(['name' => 'Ada Automation']);
        $this->join($longProgram, $ada);
        $this->join($shortProgram, $ada);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '>W2</th>', '>W3</th>', '>W4</th>',
                '>Four weeks</span>', '0 / 2 (0%)', '0 / 1 (0%)', '0 / 1 (0%)', '0 / 4 (0%)',
                '>Two weeks</span>', '0 / 2 (0%)', '>—</td>', '>—</td>', '>—</td>', '0 / 2 (0%)',
            ], false);
    }

    public function test_week_one_scores_appear_in_the_overview_and_the_week_filter_offers_week_one(): void
    {
        $activities = $this->program([1 => 1, 2 => 1, 3 => 1, 4 => 1]);
        $wendy = User::factory()->create(['name' => 'Wendy WeekOne']);
        $tom = User::factory()->create(['name' => 'Tom NoWork']);
        $this->join($activities[1][0]->week->program, $wendy, $tom);
        $this->score($wendy, $activities[1][0], 92, 'Exemplary');

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.scoring.index', ['week' => 1]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSeeInOrder([
                '<option value="1" selected>Week 1</option>', '<option value="2" >Week 2</option>', '<option value="3" >Week 3</option>', '<option value="4" >Week 4</option>',
                '>W1</th>', '>W1 Completion</th>', '>W2</th>', '>W3</th>', '>W4</th>',
                '>Wendy WeekOne</strong>', '>92</strong>', 'Exemplary', '1 / 1 (100%)',
                '—', '0 / 1 (0%)', '—', '0 / 1 (0%)', '—', '0 / 1 (0%)',
                '>92</td>', '1 / 4 (25%)',
            ], false)
            ->assertDontSee('>Tom NoWork</strong>', false);
    }

    public function test_filters_keep_their_values_and_look_at_the_work_in_the_rows_curriculum(): void
    {
        $activities = $this->program(name: 'Curriculum A');
        $programA = $activities[2][0]->week->program;
        $programB = $this->program(name: 'Curriculum B')[2][0]->week->program;
        $scored = User::factory()->create(['name' => 'Ada Scored']);
        $unscored = User::factory()->create(['name' => 'Ada Unscored']);
        User::factory()->create(['name' => 'Ada Elsewhere']);
        $this->join($programA, $scored, $unscored);
        $this->join($programB, $scored);
        $this->score($scored, $activities[2][0], 83, 'Proficient');
        $this->score($unscored, $activities[2][0], 65, 'Developing');
        $filters = ['search' => 'Ada', 'week' => 2, 'score_status' => 'scored', 'band' => 'Proficient'];

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.scoring.index', $filters))
            ->assertOk()
            ->assertSee('name="search" value="Ada"', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="scored" selected>Scored</option>', false)
            ->assertSee('<option value="Proficient" selected>Proficient</option>', false)
            ->assertDontSee('name="path"', false)
            ->assertSeeInOrder(['>Ada Scored</strong>', '>Curriculum A</span>'], false)
            ->assertDontSee('>Curriculum B</span>', false)
            ->assertDontSee('>Ada Unscored</strong>', false)
            ->assertDontSee('>Ada Elsewhere</strong>', false);
    }

    public function test_a_participant_with_only_a_draft_score_is_matched_by_the_unscored_filter_and_not_by_scored_or_band(): void
    {
        $activities = $this->program();
        $drafted = User::factory()->create(['name' => 'Dana Draft']);
        $scored = User::factory()->create(['name' => 'Sam Scored']);
        $this->join($activities[2][0]->week->program, $drafted, $scored);
        $this->score($drafted, $activities[2][0], 83, 'Proficient', 'draft');
        $this->score($scored, $activities[2][0], 84, 'Proficient');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.scoring.index', ['score_status' => 'unscored']))
            ->assertOk()
            ->assertSee('>Dana Draft</strong>', false)
            ->assertDontSee('>Sam Scored</strong>', false);

        foreach ([['score_status' => 'scored'], ['band' => 'Proficient']] as $filters) {
            $this->actingAs($admin)->get(route('coach.scoring.index', $filters))
                ->assertOk()
                ->assertSee('>Sam Scored</strong>', false)
                ->assertDontSee('>Dana Draft</strong>', false);
        }
    }

    public function test_rows_are_paginated_by_25_and_the_page_links_keep_the_filters(): void
    {
        $activities = $this->program();
        foreach (range(1, 26) as $number) {
            $this->join($activities[2][0]->week->program, User::factory()->create(['name' => sprintf('Participant %02d', $number)]));
        }
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.scoring.index', ['search' => 'Participant']))
            ->assertOk()
            ->assertSee('>Participant 25</strong>', false)
            ->assertDontSee('>Participant 26</strong>', false)
            ->assertSee('search=Participant&amp;page=2', false);

        $this->actingAs($admin)->get(route('coach.scoring.index', ['search' => 'Participant', 'page' => 2]))
            ->assertOk()
            ->assertSee('>Participant 26</strong>', false)
            ->assertDontSee('>Participant 25</strong>', false);
    }

    public function test_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        $activities = $this->program();
        $this->join($activities[2][0]->week->program, User::factory()->create(['name' => 'Some Person']));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.scoring.index', ['search' => 'Nobody']))
            ->assertOk()
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-scoring-row', false)
            ->assertDontSee('<table', false);
    }

    public function test_an_old_path_or_assigned_to_me_filter_is_ignored(): void
    {
        $activities = $this->program();
        $this->join($activities[2][0]->week->program, User::factory()->create(['name' => 'Mia Manual']), User::factory()->create(['name' => 'Abe Automation']));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.scoring.index', ['path' => 'automation', 'assigned_to_me' => 1]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('>Mia Manual</strong>', false)
            ->assertSee('>Abe Automation</strong>', false);
    }

    public function test_the_table_scrolls_inside_a_positioned_region_so_its_screen_reader_only_header_cannot_widen_the_page(): void
    {
        $activities = $this->program();
        $this->join($activities[2][0]->week->program, User::factory()->create(['name' => 'Manual Person']));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="scoring-participants-title" tabindex="0">',
                '<span class="sr-only">Action</span>',
            ], false);
    }

    public function test_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $this->assertSame($this->overviewQueryCount(1), $this->overviewQueryCount(3));
    }

    /**
     * An active curriculum (weeks open today) with the given number of active activities per week (by default two in
     * Week 2 and one each in Weeks 3 and 4).
     *
     * @param  array<int, int>  $activityCounts  keyed by week number
     * @return array<int, list<Activity>> keyed by week number
     */
    private function program(array $activityCounts = [2 => 2, 3 => 1, 4 => 1], ?string $name = null): array
    {
        $program = Program::factory()->create($name === null ? [] : ['name' => $name]);

        return collect($activityCounts)
            ->map(function (int $activityCount, int $weekNumber) use ($program): array {
                $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);

                return collect(range(1, $activityCount))
                    ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]))
                    ->all();
            })
            ->all();
    }

    /**
     * Two Weeks 2–4 curricula: X is active and Y starts next week. In X an admin assigned Ana and then Ben as Pat's
     * mentor, and Dan mentors Quinn; in Y Cy mentors Pat. Pat has a published Week 2 score of 83 in X and 60 in Y.
     *
     * @return array{programX: Program, programY: Program, pat: User, ana: User, ben: User, cy: User, dan: User}
     */
    private function mentorScopeFixture(): array
    {
        $activitiesX = $this->program(name: 'Curriculum X');
        $activitiesY = $this->program(name: 'Curriculum Y');
        $programX = $activitiesX[2][0]->week->program;
        $programY = $activitiesY[2][0]->week->program;
        $programY->weeks()->update(['starts_on' => today()->addWeek(), 'ends_on' => today()->addWeeks(2)->subDay()]);
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        $ana = User::factory()->create(['role' => 'coach', 'name' => 'Ana Earlier']);
        $ben = User::factory()->create(['role' => 'coach', 'name' => 'Ben Current']);
        $cy = User::factory()->create(['role' => 'coach', 'name' => 'Cy Other']);
        $dan = User::factory()->create(['role' => 'coach', 'name' => 'Dan Quinns']);
        $this->mentor($programX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($programX, $ben, $pat);
        $this->mentor($programX, $dan, $quinn);
        $this->mentor($programY, $cy, $pat);
        $this->score($pat, $activitiesX[2][0], 83, 'Proficient');
        $this->score($pat, $activitiesY[2][0], 60, 'Developing');

        return ['programX' => $programX, 'programY' => $programY, 'pat' => $pat, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan];
    }

    /**
     * Add the coach to the curriculum's mentors and the participants to the curriculum, with the coach as their
     * current mentor there.
     */
    private function mentor(Program $program, User $coach, User ...$participants): void
    {
        $program->mentors()->syncWithoutDetaching([$coach->id]);
        $this->join($program, ...$participants);
        foreach ($participants as $participant) {
            MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * Add the participants to the curriculum.
     */
    private function join(Program $program, User ...$participants): void
    {
        foreach ($participants as $participant) {
            $program->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        }
    }

    /**
     * The curriculum filter's option label: "Name (dates)".
     */
    private function optionLabel(Program $program): string
    {
        return "{$program->name} ({$program->dateRangeLabel()})";
    }

    /**
     * Build a new active curriculum with the given number of participants, each mentored by one coach and
     * with a published Week 2 score, and count the queries of that coach's overview request after a warm-up request.
     */
    private function overviewQueryCount(int $participantCount): int
    {
        $activities = $this->program();
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (User::factory()->count($participantCount)->create() as $participant) {
            $this->mentor($activities[2][0]->week->program, $coach, $participant);
            $this->score($participant, $activities[2][0], 83, 'Proficient');
        }

        $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk();
        DB::disableQueryLog();
        $this->assertSame($participantCount, substr_count($response->getContent(), '>83</strong>'));

        return count(DB::getQueryLog());
    }

    private function score(User $participant, Activity $activity, int $total, string $band, string $status = 'published'): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        ActivityScore::create([
            'submission_version_id' => $version->id,
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => $status,
            'total_score' => $total,
            'performance_band' => $band,
        ]);
    }
}
