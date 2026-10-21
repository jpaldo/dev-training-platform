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
use Tests\TestCase;

class ScoringOverviewPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_each_participant_with_weekly_and_overall_scores_and_completion(): void
    {
        $activities = $this->program();
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor']);
        $ada = User::factory()->create(['name' => 'Ada Automation', 'email' => 'ada@example.com', 'training_path' => 'automation']);
        $ben = User::factory()->create(['name' => 'Ben Both', 'email' => 'ben@example.com', 'training_path' => 'automation_manual']);
        $coach->assignedParticipants()->attach($ada->id);
        $mentor->assignedParticipants()->attach($ada->id);
        $this->publishedScore($ada, $activities[2][0], 83, 'Proficient');

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
            '>Ada Automation</strong>', 'ada@example.com', 'Automation QA',
            '>83</strong>', 'Proficient', '1 / 2 (50%)',
            '—', '0 / 1 (0%)',
            '—', '0 / 1 (0%)',
            '>83</td>', '1 / 4 (25%)', 'Coach Carter, Mia Mentor',
            'href="'.route('coach.scoring.participant', $ada).'"', '>View Scoring</a>',
            '>Ben Both</strong>', 'ben@example.com', 'Manual QA',
            '—', '0 / 2 (0%)', '—', '0 / 1 (0%)', '—', '0 / 1 (0%)',
            '>—</td>', '0 / 4 (0%)', 'Unassigned',
            'href="'.route('coach.scoring.participant', $ben).'"', '>View Scoring</a>',
        ], false);
        $response->assertDontSee('from-brand to-brand-deep', false)->assertDontSee('No participants match these filters.');
    }

    public function test_filters_keep_their_values_and_assigned_to_me_only_narrows_the_list_for_a_coach(): void
    {
        $activities = $this->program();
        $coach = User::factory()->create(['role' => 'coach']);
        $assigned = User::factory()->create(['name' => 'Ada Assigned', 'training_path' => 'automation']);
        $unassigned = User::factory()->create(['name' => 'Ada Unassigned', 'training_path' => 'automation']);
        User::factory()->create(['name' => 'Ada Manual', 'training_path' => 'manual']);
        $coach->assignedParticipants()->attach($assigned->id);
        $this->publishedScore($assigned, $activities[2][0], 83, 'Proficient');
        $this->publishedScore($unassigned, $activities[2][0], 84, 'Proficient');
        $filters = ['search' => 'Ada', 'path' => 'automation', 'week' => 2, 'score_status' => 'scored', 'band' => 'Proficient', 'assigned_to_me' => 1];

        $this->actingAs($coach)->get(route('coach.scoring.index', $filters))
            ->assertOk()
            ->assertSee('name="search" value="Ada"', false)
            ->assertSee('<option value="automation" selected>Automation QA</option>', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="scored" selected>Scored</option>', false)
            ->assertSee('<option value="Proficient" selected>Proficient</option>', false)
            ->assertSee('name="assigned_to_me" value="1" checked', false)
            ->assertDontSee('<option value="manual" selected>', false)
            ->assertSee('>Ada Assigned</strong>', false)
            ->assertDontSee('>Ada Unassigned</strong>', false)
            ->assertDontSee('>Ada Manual</strong>', false);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.scoring.index', $filters))
            ->assertOk()
            ->assertSeeInOrder(['>Ada Assigned</strong>', '>Ada Unassigned</strong>'], false)
            ->assertDontSee('>Ada Manual</strong>', false);
    }

    public function test_participants_are_paginated_by_25_and_the_page_links_keep_the_filters(): void
    {
        $this->program();
        foreach (range(1, 26) as $number) {
            User::factory()->create(['name' => sprintf('Participant %02d', $number), 'training_path' => 'manual']);
        }
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.scoring.index', ['path' => 'manual']))
            ->assertOk()
            ->assertSee('>Participant 25</strong>', false)
            ->assertDontSee('>Participant 26</strong>', false)
            ->assertSee('path=manual&amp;page=2', false);

        $this->actingAs($coach)->get(route('coach.scoring.index', ['path' => 'manual', 'page' => 2]))
            ->assertOk()
            ->assertSee('>Participant 26</strong>', false)
            ->assertDontSee('>Participant 25</strong>', false);
    }

    public function test_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        $this->program();
        User::factory()->create(['name' => 'Manual Person', 'training_path' => 'manual']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index', ['path' => 'automation']))
            ->assertOk()
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-scoring-row', false)
            ->assertDontSee('<table', false);
    }

    public function test_the_table_scrolls_inside_a_positioned_region_so_its_screen_reader_only_header_cannot_widen_the_page(): void
    {
        $this->program();
        User::factory()->create(['name' => 'Manual Person', 'training_path' => 'manual']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="scoring-participants-title" tabindex="0">',
                '<span class="sr-only">Action</span>',
            ], false);
    }

    public function test_without_an_active_program_the_overview_is_not_found(): void
    {
        Program::factory()->create(['is_active' => false]);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.scoring.index'))->assertNotFound();
    }

    /**
     * An active program with two active activities in Week 2 and one each in Weeks 3 and 4.
     *
     * @return array<int, list<Activity>> keyed by week number
     */
    private function program(): array
    {
        $program = Program::factory()->create();

        return collect([2 => 2, 3 => 1, 4 => 1])
            ->map(function (int $activityCount, int $weekNumber) use ($program): array {
                $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);

                return collect(range(1, $activityCount))
                    ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]))
                    ->all();
            })
            ->all();
    }

    private function publishedScore(User $participant, Activity $activity, int $total, string $band): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        ActivityScore::create([
            'submission_version_id' => $version->id,
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => 'published',
            'total_score' => $total,
            'performance_band' => $band,
        ]);
    }
}
