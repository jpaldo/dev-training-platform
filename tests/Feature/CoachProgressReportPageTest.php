<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachProgressReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_report_navigation_summary_at_risk_rows_and_participant_progress(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Blake']);

        $risky = User::factory()->create(['name' => 'Riley Risk', 'email' => 'riley@example.test', 'training_path' => 'automation_manual']);
        $risky->assignedCoaches()->attach([$admin->id, $coach->id]);
        $revision = ActivitySubmission::factory()->for($risky, 'participant')->create(['status' => 'needs_revision']);
        $failedGate = GateEvaluation::factory()->for($risky, 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for($risky, 'participant')->create(['status' => 'passed']);

        $steady = User::factory()->create(['name' => 'Sam Steady', 'email' => 'sam@example.test', 'training_path' => 'manual', 'is_active' => false]);
        ActivitySubmission::factory()->for($steady, 'participant')->create(['status' => 'approved']);

        $response = $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH / REPORTS',
                'Progress report',
                'href="'.e(route('coach.reports.export', ['type' => 'progress'])).'"', '>Export Progress CSV</a>',
                'aria-label="Reports"',
                'href="'.route('coach.reports').'"', 'aria-current="page"', '>Progress</a>',
                'href="'.route('coach.reports.daily-logs').'"', '>Daily Log Completion</a>',
                'href="'.route('coach.reports.daily-logs.details').'"', '>Daily Log Details</a>',
                'href="'.route('coach.reports.missing-submissions').'"', '>Missing Submissions</a>',
                '<option value="">All mentors</option>',
                '<option value="'.$admin->id.'"', '>Admin Blake</option>',
                '<option value="'.$coach->id.'"', '>Coach Avery</option>',
                'Total Participants', '>2</dd>',
                'Active Participants', '>1</dd>',
                'Submissions', '>2</dd>',
                'Gates Passed', '>1</dd>',
                'At Risk', '>1</dd>',
                'At-risk participants',
                '>Riley Risk</th>',
                '>Automation / Manual</span>',
                '>'.e($revision->activity->title).': Needs revision · '.e($failedGate->gate->title).': Failed</td>',
                'Participant progress',
                '2 participant(s)',
                '>Riley Risk</strong>', '>riley@example.test</span>',
                '>Automation / Manual</span>',
                '>Admin Blake, Coach Avery</td>',
                '>1</td>', '>1</td>',
                '>Sam Steady</strong>', '>sam@example.test</span>',
                '>Manual</span>',
                '>Not assigned</td>',
                '>1</td>', '>0</td>',
            ], false)
            ->assertDontSee('No current at-risk participants.')
            ->assertDontSee('No participants match the selected filters.');

        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $response->getContent(), $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $activity = Activity::factory()->create();
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create(['training_path' => 'automation']);
        $mentor->assignedParticipants()->attach($participants->modelKeys());
        $participants->each(fn (User $participant): ActivitySubmission => ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'failed']));
        User::factory()->create(['name' => 'Page Manual', 'training_path' => 'manual'])->assignedCoaches()->attach($mentor->id);
        User::factory()->create(['name' => 'Page Unassigned', 'training_path' => 'automation']);
        $filters = ['search' => 'Page', 'path' => 'automation', 'mentor' => $mentor->id];

        $firstPage = $this->actingAs($mentor)->get(route('coach.reports', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertSee('<option value="automation" selected>Automation</option>', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('href="'.e(route('coach.reports.export', [...$filters, 'type' => 'progress'])).'"', false)
            ->assertSee('26 participant(s)')
            ->assertSee(e(route('coach.reports', [...$filters, 'risk_page' => 2])), false)
            ->assertSee(e(route('coach.reports', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Page Manual')
            ->assertDontSee('Page Unassigned');

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-at-risk-row'));
        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-participant-row'));

        $secondRiskPage = $this->actingAs($mentor)->get(route('coach.reports', [...$filters, 'risk_page' => 2]))
            ->assertOk()
            ->assertSee('>Page Person 26</th>', false)
            ->assertSee(e(route('coach.reports', [...$filters, 'risk_page' => 2, 'page' => 2])), false);

        $this->assertSame(1, substr_count($secondRiskPage->getContent(), 'data-at-risk-row'));
        $this->assertSame(25, substr_count($secondRiskPage->getContent(), 'data-participant-row'));
    }

    public function test_report_without_participants_shows_both_notices(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Total Participants', '>0</dd>', 'At Risk', '>0</dd>'], false)
            ->assertSee('No current at-risk participants.')
            ->assertSee('0 participant(s)')
            ->assertSee('No participants match the selected filters.');

        $this->assertStringNotContainsString('<table', $response->getContent());
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->reportQueryCount(1), $this->reportQueryCount(3));
    }

    /**
     * Create the given number of participants, each assigned to a new coach with a submission awaiting revision and
     * a failed and a passed Gate evaluation, and count the queries of that coach's report request after a warm-up
     * request.
     */
    private function reportQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participants = User::factory()->count($participantCount)->create();
        $coach->assignedParticipants()->attach($participants->modelKeys());

        foreach ($participants as $participant) {
            ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'needs_revision']);
            GateEvaluation::factory()->for($participant, 'participant')->create(['status' => 'failed']);
            GateEvaluation::factory()->for($participant, 'participant')->create(['status' => 'passed']);
        }

        $this->actingAs($coach)->get(route('coach.reports'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.reports'))->assertOk()->assertSee('Needs revision');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
