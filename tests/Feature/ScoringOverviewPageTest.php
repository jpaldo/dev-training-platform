<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
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

    public function test_coach_sees_each_participant_with_weekly_and_overall_scores_and_completion(): void
    {
        $activities = $this->program();
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor']);
        $ada = User::factory()->create(['name' => 'Ada Automation', 'email' => 'ada@example.com']);
        $ben = User::factory()->create(['name' => 'Ben Both', 'email' => 'ben@example.com']);
        $coach->assignedParticipants()->attach($ada->id);
        $mentor->assignedParticipants()->attach($ada->id);
        $this->score($ada, $activities[2][0], 83, 'Proficient');

        $response = $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk();

        $response->assertSeeInOrder([
            'COACH · SCORING',
            '>Scoring overview</h1>',
            'Completion and activity scores are separate measures.',
            'href="'.route('coach.scoring.criteria').'"', '>View Scoring Criteria</a>',
            'href="'.route('coach.scoring.reports').'"', '>Scoring Reports</a>',
            'aria-label="Filter participants"',
            '>W2</th>', '>W2 Completion</th>', '>W3</th>', '>W3 Completion</th>', '>W4</th>', '>W4 Completion</th>',
            '>Overall</th>', '>Completion</th>', '>Assigned Coach</th>',
            '>Ada Automation</strong>', 'ada@example.com',
            '>83</strong>', 'Proficient', '1 / 2 (50%)',
            '—', '0 / 1 (0%)',
            '—', '0 / 1 (0%)',
            '>83</td>', '1 / 4 (25%)', 'Coach Carter, Mia Mentor',
            'href="'.route('coach.scoring.participant', $ada).'"', '>View Scoring</a>',
            '>Ben Both</strong>', 'ben@example.com',
            '—', '0 / 2 (0%)', '—', '0 / 1 (0%)', '—', '0 / 1 (0%)',
            '>—</td>', '0 / 4 (0%)', 'Unassigned',
            'href="'.route('coach.scoring.participant', $ben).'"', '>View Scoring</a>',
        ], false);
        $response->assertDontSee('from-brand to-brand-deep', false)->assertDontSee('No participants match these filters.')->assertDontSee('QA Path')->assertDontSee('Automation / Manual');
    }

    public function test_week_one_scores_appear_in_the_overview_and_the_week_filter_offers_week_one(): void
    {
        $activities = $this->program([1 => 1, 2 => 1, 3 => 1, 4 => 1]);
        $wendy = User::factory()->create(['name' => 'Wendy WeekOne']);
        User::factory()->create(['name' => 'Tom NoWork']);
        $this->score($wendy, $activities[1][0], 92, 'Exemplary');

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.scoring.index', ['week' => 1]))
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

    public function test_filters_keep_their_values_and_assigned_to_me_only_narrows_the_list_for_a_coach(): void
    {
        $activities = $this->program();
        $coach = User::factory()->create(['role' => 'coach']);
        $assigned = User::factory()->create(['name' => 'Ada Assigned']);
        $unassigned = User::factory()->create(['name' => 'Ada Unassigned']);
        User::factory()->create(['name' => 'Ada Manual']);
        $coach->assignedParticipants()->attach($assigned->id);
        $this->score($assigned, $activities[2][0], 83, 'Proficient');
        $this->score($unassigned, $activities[2][0], 84, 'Proficient');
        $filters = ['search' => 'Ada', 'week' => 2, 'score_status' => 'scored', 'band' => 'Proficient', 'assigned_to_me' => 1];

        $this->actingAs($coach)->get(route('coach.scoring.index', $filters))
            ->assertOk()
            ->assertSee('name="search" value="Ada"', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="scored" selected>Scored</option>', false)
            ->assertSee('<option value="Proficient" selected>Proficient</option>', false)
            ->assertSee('name="assigned_to_me" value="1" checked', false)
            ->assertDontSee('name="path"', false)
            ->assertSee('>Ada Assigned</strong>', false)
            ->assertDontSee('>Ada Unassigned</strong>', false)
            ->assertDontSee('>Ada Manual</strong>', false);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.scoring.index', $filters))
            ->assertOk()
            ->assertSeeInOrder(['>Ada Assigned</strong>', '>Ada Unassigned</strong>'], false)
            ->assertDontSee('>Ada Manual</strong>', false);
    }

    public function test_a_participant_with_only_a_draft_score_is_matched_by_the_unscored_filter_and_not_by_scored_or_band(): void
    {
        $activities = $this->program();
        $drafted = User::factory()->create(['name' => 'Dana Draft']);
        $scored = User::factory()->create(['name' => 'Sam Scored']);
        $this->score($drafted, $activities[2][0], 83, 'Proficient', 'draft');
        $this->score($scored, $activities[2][0], 84, 'Proficient');
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.scoring.index', ['score_status' => 'unscored']))
            ->assertOk()
            ->assertSee('>Dana Draft</strong>', false)
            ->assertDontSee('>Sam Scored</strong>', false);

        foreach ([['score_status' => 'scored'], ['band' => 'Proficient']] as $filters) {
            $this->actingAs($coach)->get(route('coach.scoring.index', $filters))
                ->assertOk()
                ->assertSee('>Sam Scored</strong>', false)
                ->assertDontSee('>Dana Draft</strong>', false);
        }
    }

    public function test_participants_are_paginated_by_25_and_the_page_links_keep_the_filters(): void
    {
        $this->program();
        foreach (range(1, 26) as $number) {
            User::factory()->create(['name' => sprintf('Participant %02d', $number)]);
        }
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.scoring.index', ['search' => 'Participant']))
            ->assertOk()
            ->assertSee('>Participant 25</strong>', false)
            ->assertDontSee('>Participant 26</strong>', false)
            ->assertSee('search=Participant&amp;page=2', false);

        $this->actingAs($coach)->get(route('coach.scoring.index', ['search' => 'Participant', 'page' => 2]))
            ->assertOk()
            ->assertSee('>Participant 26</strong>', false)
            ->assertDontSee('>Participant 25</strong>', false);
    }

    public function test_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        $this->program();
        User::factory()->create(['name' => 'Some Person']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index', ['search' => 'Nobody']))
            ->assertOk()
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-scoring-row', false)
            ->assertDontSee('<table', false);
    }

    public function test_an_old_path_filter_is_ignored(): void
    {
        $this->program();
        User::factory()->create(['name' => 'Mia Manual']);
        User::factory()->create(['name' => 'Abe Automation']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('>Mia Manual</strong>', false)
            ->assertSee('>Abe Automation</strong>', false);
    }

    public function test_the_table_scrolls_inside_a_positioned_region_so_its_screen_reader_only_header_cannot_widen_the_page(): void
    {
        $this->program();
        User::factory()->create(['name' => 'Manual Person']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="scoring-participants-title" tabindex="0">',
                '<span class="sr-only">Action</span>',
            ], false);
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->overviewQueryCount(1), $this->overviewQueryCount(3));
    }

    public function test_without_an_active_program_the_overview_says_so_and_shows_no_week_columns(): void
    {
        TrainingWeek::factory()->for(Program::factory()->state(['is_active' => false]))->create(['week_number' => 1, 'position' => 1]);
        User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder(['No training program is active yet.', 'Ada Lovelace', '0 / 0 (0%)'])
            ->assertDontSee('>W1<', false);
    }

    /**
     * An active program with the given number of active activities per week (by default two in Week 2 and one each in
     * Weeks 3 and 4).
     *
     * @param  array<int, int>  $activityCounts  keyed by week number
     * @return array<int, list<Activity>> keyed by week number
     */
    private function program(array $activityCounts = [2 => 2, 3 => 1, 4 => 1]): array
    {
        $program = Program::factory()->create();

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
     * Build a new active program with the given number of participants, each with a published Week 2 score, and
     * count the queries of a coach's overview request after a warm-up request.
     */
    private function overviewQueryCount(int $participantCount): int
    {
        Program::query()->update(['is_active' => false]);
        $activities = $this->program();
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (User::factory()->count($participantCount)->create() as $participant) {
            $this->score($participant, $activities[2][0], 83, 'Proficient');
        }

        $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk()->assertSee('>83</strong>', false);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function score(User $participant, Activity $activity, int $total, string $band, string $status = 'published'): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
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
