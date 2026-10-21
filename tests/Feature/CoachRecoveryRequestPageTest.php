<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachRecoveryRequestPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_page_lists_pending_requests_newest_first_with_their_decision_form(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create(['title' => 'Risk charter']);
        $older = $this->recoveryRequest($activity, 'Olga Older', 1, 'Please reconsider this attempt.', now()->subDay());
        $newer = $this->recoveryRequest($activity, 'Nina Newer', 2, null, now());
        $this->recoveryRequest($activity, 'Dina Declined', 1, 'Declined already.', now(), 'declined');

        $this->actingAs($coach)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH OPERATIONS',
                '>Recovery requests</h1>',
                'aria-label="Pending recovery requests"',
                '>Week 2 · Risk charter</h2>', 'Nina Newer · Attempt 2 ·', '>FAILED</span>', 'No reason provided.',
                'action="'.route('coach.recovery.decide', $newer).'"', 'name="coach_response"', 'placeholder="Coach response"',
                'name="decision" value="approved"', '>Approve Retry</button>',
                'name="decision" value="declined"', '>Keep Failed</button>',
                '>Week 2 · Risk charter</h2>', 'Olga Older · Attempt 1 ·', '>FAILED</span>', 'Please reconsider this attempt.',
                'action="'.route('coach.recovery.decide', $older).'"',
            ], false)
            ->assertDontSee('Dina Declined')
            ->assertDontSee('No pending recovery requests.');
    }

    public function test_page_shows_25_requests_per_page(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();

        foreach (range(1, 26) as $index) {
            $this->recoveryRequest($activity, "Participant {$index}", 1, null, now()->subMinutes($index));
        }

        $this->actingAs($coach)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('Participant 1 · Attempt', false)
            ->assertDontSee('Participant 26 · Attempt', false)
            ->assertSee(e(route('coach.recovery.index', ['page' => 2])), false);

        $this->actingAs($coach)->get(route('coach.recovery.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Participant 26 · Attempt', false)
            ->assertDontSee('Participant 1 · Attempt', false);
    }

    public function test_page_without_pending_requests_shows_a_notice_instead_of_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('No pending recovery requests.')
            ->assertDontSee('aria-label="Pending recovery requests"', false);
    }

    public function test_page_query_count_does_not_grow_with_the_number_of_requests(): void
    {
        $this->assertSame($this->recoveryRequestPageQueryCount(1), $this->recoveryRequestPageQueryCount(3));
    }

    /**
     * Create a recovery request with the given status, message and creation time for a failed attempt of the
     * activity by a new participant with the given name.
     */
    private function recoveryRequest(Activity $activity, string $participantName, int $attemptNumber, ?string $message, CarbonInterface $createdAt, string $status = 'pending'): ActivityRecoveryRequest
    {
        $submission = ActivitySubmission::factory()
            ->for($activity)
            ->for(User::factory()->state(['name' => $participantName]), 'participant')
            ->create(['status' => 'failed', 'attempt_number' => $attemptNumber]);

        return ActivityRecoveryRequest::forceCreate([
            'user_id' => $submission->user_id,
            'activity_submission_id' => $submission->id,
            'status' => $status,
            'message' => $message,
            'requested_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Create the given number of pending requests, each for a new activity in a new week, and count the queries of a
     * coach's page request after a warm-up request.
     */
    private function recoveryRequestPageQueryCount(int $requestCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (range(1, $requestCount) as $index) {
            $this->recoveryRequest(Activity::factory()->create(), "Participant {$index}", 1, null, now());
        }

        $this->actingAs($coach)->get(route('coach.recovery.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.recovery.index'))->assertOk()->assertSee('Approve Retry');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
