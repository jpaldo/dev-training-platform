<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachReviewQueuePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_queue_lists_reviewable_submissions_newest_first_with_a_link_to_their_latest_version(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create(['title' => 'Exploratory charter']);
        $revised = $this->submission($activity, 'Rita Revised', 'needs_revision', now()->subDay());
        $olderVersion = SubmissionVersion::factory()->for($revised, 'submission')->create(['version_number' => 1]);
        $latestVersion = SubmissionVersion::factory()->for($revised, 'submission')->create(['version_number' => 2]);
        $this->submission($activity, 'Nora Noversion', 'submitted', now());
        $this->submission($activity, 'Dora Draft', 'draft', now());
        $this->submission($activity, 'Abe Approved', 'approved', now());

        $this->actingAs($coach)->get(route('coach.reviews'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH · REVIEW QUEUE',
                'Submission reviews',
                '>Nora Noversion</h2>', 'Week 3 · Exploratory charter', 'Status:', 'border-accent text-cyan-200">Submitted</span>',
                '>Rita Revised</h2>', 'Week 3 · Exploratory charter', 'Status:', 'border-amber-500 text-amber-200">Needs revision</span>',
                'href="'.route('coach.reviews.show', $latestVersion).'"', '>Review latest version</a>',
            ], false)
            ->assertDontSee('href="'.route('coach.reviews.show', $olderVersion).'"', false)
            ->assertDontSee('Dora Draft')
            ->assertDontSee('Abe Approved')
            ->assertDontSee('No submissions to review.');

        $this->assertSame(1, substr_count($this->actingAs($coach)->get(route('coach.reviews'))->getContent(), 'Review latest version'));
    }

    public function test_filters_apply_and_the_pagination_links_keep_them(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();

        foreach (range(1, 26) as $index) {
            $this->submission($activity, "Revision {$index}", 'needs_revision', now()->subMinutes($index));
        }

        $this->submission($activity, 'Sam Submitted', 'submitted', now());

        $this->actingAs($coach)->get(route('coach.reviews', ['status' => 'needs_revision']))
            ->assertOk()
            ->assertSee('>Revision 1</h2>', false)
            ->assertDontSee('Sam Submitted')
            ->assertDontSee('>Revision 26</h2>', false)
            ->assertSee(e(route('coach.reviews', ['status' => 'needs_revision', 'page' => 2])), false);

        $this->actingAs($coach)->get(route('coach.reviews', ['status' => 'needs_revision', 'page' => 2]))
            ->assertOk()
            ->assertSee('>Revision 26</h2>', false)
            ->assertDontSee('>Revision 1</h2>', false);
    }

    #[DataProvider('statusFilterValues')]
    public function test_status_filter_accepts_the_review_statuses_and_the_gate_statuses_it_lists(string $status, bool $isAccepted): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reviews', ['status' => $status]));

        if ($isAccepted) {
            $response->assertOk()->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
        }
    }

    #[DataProvider('statusFilterValues')]
    public function test_report_export_status_filter_accepts_the_same_statuses_as_the_queue(string $status, bool $isAccepted): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.export', ['type' => 'reviews', 'status' => $status]));

        if ($isAccepted) {
            $response->assertOk()->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
        }
    }

    /**
     * Every submission and Gate status value and whether the mixed `status` filter accepts it: the four submission
     * statuses of the queue and the three Gate statuses of the Gate export.
     *
     * @return array<string, array{string, bool}>
     */
    public static function statusFilterValues(): array
    {
        return [
            'submitted' => ['submitted', true],
            'resubmitted' => ['resubmitted', true],
            'under review' => ['under_review', true],
            'needs revision' => ['needs_revision', true],
            'gate not ready' => ['not_ready', true],
            'failed' => ['failed', true],
            'gate passed' => ['passed', true],
            'draft' => ['draft', false],
            'approved' => ['approved', false],
            'completed' => ['completed', false],
            'gate ready for review' => ['ready_for_review', false],
            'gate awaiting review' => ['awaiting_review', false],
        ];
    }

    public function test_empty_queue_shows_a_notice_instead_of_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reviews'))
            ->assertOk()
            ->assertSee('No submissions to review.')
            ->assertDontSee('aria-label="Submissions to review"', false);
    }

    public function test_queue_query_count_does_not_grow_with_the_number_of_submissions(): void
    {
        $this->assertSame($this->reviewQueueQueryCount(1), $this->reviewQueueQueryCount(3));
    }

    /**
     * Create a submission of the activity by a new participant with the given name, status and submission time.
     */
    private function submission(Activity $activity, string $participantName, string $status, CarbonInterface $submittedAt): ActivitySubmission
    {
        return ActivitySubmission::factory()
            ->for($activity)
            ->for(User::factory()->state(['name' => $participantName]), 'participant')
            ->create(['status' => $status, 'submitted_at' => $submittedAt]);
    }

    /**
     * Create the given number of submitted submissions of a new activity (each by a new participant, with two
     * versions) and count the queries of a coach's queue request filtered to that activity after a warm-up request.
     */
    private function reviewQueueQueryCount(int $submissionCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();

        foreach (range(1, $submissionCount) as $index) {
            $submission = $this->submission($activity, "Participant {$index}", 'submitted', now());
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2]);
        }

        $url = route('coach.reviews', ['activity' => $activity->id]);
        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee('Review latest version');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
