<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\SubmissionVersion;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoachProgressReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_report_navigation_summary_at_risk_rows_and_participant_progress(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Blake']);

        $risky = User::factory()->create(['name' => 'Riley Risk', 'email' => 'riley@example.test']);
        MentorAssignment::factory()->create(['participant_id' => $risky->id, 'mentor_id' => $admin->id]);
        MentorAssignment::factory()->create(['participant_id' => $risky->id, 'mentor_id' => $coach->id]);
        $revision = ActivitySubmission::factory()->for($risky, 'participant')->create(['status' => 'needs_revision']);
        $failedGate = GateEvaluation::factory()->for($risky, 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for($risky, 'participant')->create(['status' => 'passed']);

        $steady = User::factory()->create(['name' => 'Sam Steady', 'email' => 'sam@example.test', 'is_active' => false]);
        ActivitySubmission::factory()->for($steady, 'participant')->create(['status' => 'approved']);

        $response = $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR / REPORTS',
                'Progress report',
                'href="'.e(route('coach.reports.export', ['type' => 'progress'])).'"', '>Export Progress CSV</a>',
                'aria-label="Reports"',
                'href="'.route('coach.reports').'"', 'aria-current="page"', '>Progress</a>',
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
                '>'.e($revision->activity->title).': Needs revision · '.e($failedGate->gate->title).': Failed</td>',
                'Participant progress',
                '2 participant(s)',
                '>Riley Risk</strong>', '>riley@example.test</span>',
                '>Admin Blake, Coach Avery</td>',
                '>1</td>', '>1</td>',
                '>Sam Steady</strong>', '>sam@example.test</span>',
                '>Not assigned</td>',
                '>1</td>', '>0</td>',
            ], false)
            ->assertDontSee('No current at-risk participants.')
            ->assertDontSee('No participants match the selected filters.')
            ->assertDontSee('Training Path')
            ->assertDontSee('Automation / Manual');

        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $response->getContent(), $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertStringNotContainsString('Daily Log', $reportNav[1]);
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $activity = Activity::factory()->create();
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create();
        $participants->each(fn (User $participant): MentorAssignment => MentorAssignment::factory()->create(['participant_id' => $participant->id, 'mentor_id' => $mentor->id]));
        $participants->each(fn (User $participant): ActivitySubmission => ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'failed']));
        MentorAssignment::factory()->create(['participant_id' => User::factory()->create(['name' => 'Other Mentee'])->id, 'mentor_id' => $mentor->id]);
        User::factory()->create(['name' => 'Page Unassigned']);
        $filters = ['search' => 'Page', 'mentor' => $mentor->id];

        $firstPage = $this->actingAs($mentor)->get(route('coach.reports', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertDontSee('name="path"', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('href="'.e(route('coach.reports.export', [...$filters, 'type' => 'progress'])).'"', false)
            ->assertSee('26 participant(s)')
            ->assertSee(e(route('coach.reports', [...$filters, 'risk_page' => 2])), false)
            ->assertSee(e(route('coach.reports', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Other Mentee')
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

    public function test_an_old_path_filter_is_ignored_by_the_report_and_its_exports(): void
    {
        User::factory()->create(['name' => 'Mia Manual']);
        User::factory()->create(['name' => 'Abe Automation']);
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.reports', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('>Mia Manual</strong>', false)
            ->assertSee('>Abe Automation</strong>', false);

        $csv = $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'progress', 'path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->streamedContent();
        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));

        $this->assertSame(['Name', 'Employee ID', 'Email', 'Submissions', 'Gate evaluations'], $rows[0]);
        $this->assertEqualsCanonicalizing(['Mia Manual', 'Abe Automation'], array_column(array_slice($rows, 1), 0));

        foreach (['reviews', 'gates', 'risk'] as $type) {
            $this->actingAs($coach)->get(route('coach.reports.export', ['type' => $type, 'path' => 'automation']))
                ->assertOk()
                ->assertSessionHasNoErrors();
        }
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

    public function test_report_loads_only_the_at_risk_work_and_counts_the_rest(): void
    {
        $risky = User::factory()->create();
        ActivitySubmission::factory()->for($risky, 'participant')->create(['status' => 'needs_revision']);
        ActivitySubmission::factory()->count(2)->for($risky, 'participant')->create(['status' => 'approved']);
        GateEvaluation::factory()->for($risky, 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for($risky, 'participant')->create(['status' => 'passed']);
        $steady = User::factory()->create();
        ActivitySubmission::factory()->count(2)->for($steady, 'participant')->create(['status' => 'approved']);
        GateEvaluation::factory()->for($steady, 'participant')->create(['status' => 'passed']);
        $coach = User::factory()->create(['role' => 'coach']);

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Submissions', '>5</dd>', 'Gates Passed', '>2</dd>', 'At Risk', '>1</dd>'], false));

        $this->assertSame(1, $retrievedModels[ActivitySubmission::class]);
        $this->assertSame(1, $retrievedModels[GateEvaluation::class]);
        $this->assertArrayNotHasKey(SubmissionVersion::class, $retrievedModels);
        $this->assertArrayNotHasKey(AttendanceSession::class, $retrievedModels);
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
        $participants->each(fn (User $participant): MentorAssignment => MentorAssignment::factory()->create(['participant_id' => $participant->id, 'mentor_id' => $coach->id]));

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

    /**
     * Count the models of each class retrieved from the database while the callback runs.
     *
     * @return array<class-string, int>
     */
    private function retrievedModelCounts(Closure $callback): array
    {
        $counts = [];
        Event::listen('eloquent.retrieved: *', function (string $event) use (&$counts): void {
            $model = Str::after($event, 'eloquent.retrieved: ');
            $counts[$model] = ($counts[$model] ?? 0) + 1;
        });

        $callback();
        Event::forget('eloquent.retrieved: *');

        return $counts;
    }
}
