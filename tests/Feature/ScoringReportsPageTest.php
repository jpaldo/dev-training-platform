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

class ScoringReportsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_exports_filters_scoring_completion_and_band_distribution(): void
    {
        $this->submittedVersions();

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.scoring.reports'))->assertOk();

        $response->assertSeeInOrder([
            'COACH · SCORING REPORTS',
            '>Scoring reports</h1>',
            'Scoring completion is separate from activity completion. Filters apply to the dashboard and CSV exports.',
            'href="'.route('coach.reports.scores').'"', '>Activity Scores CSV</a>',
            'href="'.route('coach.reports.participant-summary').'"', '>Participant Summary CSV</a>',
            'aria-label="Scoring report filters"',
            'for="report-week"', '>Week</label>', '>Week 2</option>', '>Week 3</option>', '>Week 4</option>',
            'for="report-status"', '>Score status</label>', '>Scored</option>', '>Unscored</option>',
            'for="report-band"', '>Performance band</label>', '>Exemplary</option>', '>Minimal</option>',
            '>Apply filters</button>',
            '>COMPLETED ACTIVITIES</dt>', '>3</dd>',
            '>SCORED ACTIVITIES</dt>', '>1</dd>',
            '>SCORING COMPLETION</dt>', '>33%</dd>',
            '>Score-band distribution</h2>',
            '>Exemplary</th>', '>0</td>',
            '>Proficient</th>', '>1</td>',
            '>Developing</th>', '>0</td>',
            '>Beginning</th>', '>0</td>',
            '>Minimal</th>', '>0</td>',
            '>Not Scored</th>', '>2</td>',
        ], false);
        $response->assertDontSee('from-brand to-brand-deep', false)->assertDontSee('QA Path')->assertDontSee('name="path"', false);
    }

    public function test_filters_keep_their_values_narrow_the_counts_and_carry_over_to_the_exports(): void
    {
        $this->submittedVersions();
        $filters = ['week' => 2, 'score_status' => 'scored', 'band' => 'Proficient'];

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.scoring.reports', $filters))
            ->assertOk()
            ->assertSee('href="'.e(route('coach.reports.scores', $filters)).'"', false)
            ->assertSee('href="'.e(route('coach.reports.participant-summary', $filters)).'"', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="scored" selected>Scored</option>', false)
            ->assertSee('<option value="Proficient" selected>Proficient</option>', false)
            ->assertSeeInOrder([
                '>COMPLETED ACTIVITIES</dt>', '>1</dd>',
                '>SCORED ACTIVITIES</dt>', '>1</dd>',
                '>SCORING COMPLETION</dt>', '>100%</dd>',
                '>Proficient</th>', '>1</td>',
                '>Developing</th>', '>0</td>',
                '>Not Scored</th>', '>0</td>',
            ], false);
    }

    public function test_scoring_completion_page_shows_the_filtered_counts(): void
    {
        $this->submittedVersions();
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.reports.scoring-completion', ['week' => 2]))
            ->assertOk()
            ->assertSeeInOrder([
                'SCORING COMPLETION',
                '>Scoring completion</h1>',
                '>COMPLETED ACTIVITIES</dt>', '>2</dd>',
                '>SCORED ACTIVITIES</dt>', '>1</dd>',
                '>SCORING COMPLETION</dt>', '>50%</dd>',
            ], false);

        $this->actingAs($coach)->get(route('coach.reports.scoring-completion', ['week' => 4]))
            ->assertOk()
            ->assertSeeInOrder([
                '>COMPLETED ACTIVITIES</dt>', '>0</dd>',
                '>SCORED ACTIVITIES</dt>', '>0</dd>',
                '>SCORING COMPLETION</dt>', '>0%</dd>',
            ], false);
    }

    public function test_an_old_path_filter_is_ignored_by_the_pages_and_the_exports(): void
    {
        $this->submittedVersions();
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (['coach.scoring.reports', 'coach.reports.scoring-completion'] as $routeName) {
            $this->actingAs($coach)->get(route($routeName, ['path' => 'automation']))
                ->assertOk()
                ->assertSessionHasNoErrors()
                ->assertSeeInOrder(['>COMPLETED ACTIVITIES</dt>', '>3</dd>', '>SCORED ACTIVITIES</dt>', '>1</dd>'], false);
        }

        $scores = $this->actingAs($coach)->get(route('coach.reports.scores', ['path' => 'automation']))->assertOk()->assertSessionHasNoErrors()->streamedContent();
        $summary = $this->actingAs($coach)->get(route('coach.reports.participant-summary', ['path' => 'automation']))->assertOk()->assertSessionHasNoErrors()->streamedContent();

        $this->assertCount(4, explode("\n", trim($scores)));
        $this->assertCount(3, explode("\n", trim($summary)));
    }

    public function test_a_draft_score_is_matched_by_the_unscored_filter_and_not_by_the_scored_or_band_filters(): void
    {
        $this->submittedVersions();
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.reports.scoring-completion', ['score_status' => 'unscored']))
            ->assertOk()
            ->assertSeeInOrder(['>COMPLETED ACTIVITIES</dt>', '>2</dd>', '>SCORED ACTIVITIES</dt>', '>0</dd>'], false);

        $this->actingAs($coach)->get(route('coach.reports.scoring-completion', ['score_status' => 'scored', 'week' => 3]))
            ->assertOk()
            ->assertSeeInOrder(['>COMPLETED ACTIVITIES</dt>', '>0</dd>'], false);

        $this->actingAs($coach)->get(route('coach.reports.scoring-completion', ['band' => 'Developing']))
            ->assertOk()
            ->assertSeeInOrder(['>COMPLETED ACTIVITIES</dt>', '>0</dd>'], false);
    }

    public function test_participant_cannot_open_the_scoring_report_pages(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get(route('coach.scoring.reports'))->assertForbidden();
        $this->actingAs($participant)->get(route('coach.reports.scoring-completion'))->assertForbidden();
    }

    /**
     * A program of Weeks 2 to 4 (Week 4 without activities) with three submitted versions and one draft: a
     * participant (with the former Automation path) with a published Proficient score in Week 2 and a draft Developing
     * score in Week 3, and a participant (with the former Manual path) with an unscored Week 2 version and a draft
     * version (never counted).
     */
    private function submittedVersions(): void
    {
        $program = Program::factory()->create();
        $weekTwoActivity = Activity::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]), 'week')->create();
        $weekThreeActivity = Activity::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 3]), 'week')->create();
        TrainingWeek::factory()->for($program)->create(['week_number' => 4, 'position' => 4]);
        $ada = User::factory()->create(['training_path' => 'automation']);
        $ben = User::factory()->create(['training_path' => 'manual']);

        $this->score($this->version($ada, $weekTwoActivity), 'published', 83, 'Proficient');
        $this->score($this->version($ada, $weekThreeActivity), 'draft', 65, 'Developing');
        $this->version($ben, $weekTwoActivity);
        $this->version($ben, $weekThreeActivity, 'draft');
    }

    private function version(User $participant, Activity $activity, string $status = 'submitted'): SubmissionVersion
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => $status]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status, 'submitted_at' => now()]);
    }

    private function score(SubmissionVersion $version, string $status, int $total, string $band): void
    {
        ActivityScore::create([
            'submission_version_id' => $version->id,
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => $status,
            'total_score' => $total,
            'performance_band' => $band,
        ]);
    }
}
