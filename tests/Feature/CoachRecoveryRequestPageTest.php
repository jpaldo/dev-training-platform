<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\TrainingSession;
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
        $older = $this->recoveryRequest($coach, $activity, 'Olga Older', 1, 'Please reconsider this attempt.', now()->subDay());
        $newer = $this->recoveryRequest($coach, $activity, 'Nina Newer', 2, null, now());
        $this->recoveryRequest($coach, $activity, 'Dina Declined', 1, 'Declined already.', now(), 'declined');

        $this->actingAs($coach)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR OPERATIONS',
                '>Recovery requests</h1>',
                'action="'.route('coach.recovery.index').'"', 'aria-label="Filter recovery requests"',
                'for="recovery-session"', '<option value="">All training sessions</option>', '>Filter</button>',
                'aria-label="Pending recovery requests"',
                '>Week 2 · Risk charter</h2>', 'Nina Newer · Attempt 2 ·', '>FAILED</span>',
                'Training session:', e($newer->submission->trainingSession->titleWithDates()),
                'No reason provided.',
                'action="'.route('coach.recovery.decide', $newer).'"', 'name="coach_response"', 'placeholder="Mentor response"',
                'name="decision" value="approved"', '>Approve Retry</button>',
                'name="decision" value="declined"', '>Keep Failed</button>',
                '>Week 2 · Risk charter</h2>', 'Olga Older · Attempt 1 ·', '>FAILED</span>', 'Please reconsider this attempt.',
                'action="'.route('coach.recovery.decide', $older).'"',
            ], false)
            ->assertDontSee('Dina Declined')
            ->assertDontSee('No pending recovery requests.')
            ->assertDontSee('>Clear</a>', false);
    }

    public function test_page_shows_25_requests_per_page(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();

        foreach (range(1, 26) as $index) {
            $this->recoveryRequest($coach, $activity, "Participant {$index}", 1, null, now()->subMinutes($index));
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

    public function test_a_coach_sees_only_the_requests_of_the_participants_they_manage_and_an_admin_sees_all(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $otherCoach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();
        $managed = $this->recoveryRequest($coach, $activity, 'Mia Managed', 1, null, now());
        $unmanaged = $this->recoveryRequest($otherCoach, $activity, 'Ula Unmanaged', 1, null, now());

        $this->actingAs($coach)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('Mia Managed · Attempt 1', false)
            ->assertSee('action="'.route('coach.recovery.decide', $managed).'"', false)
            ->assertDontSee('Ula Unmanaged')
            ->assertDontSee(route('coach.recovery.decide', $unmanaged), false);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('action="'.route('coach.recovery.decide', $managed).'"', false)
            ->assertSee('action="'.route('coach.recovery.decide', $unmanaged).'"', false);
    }

    public function test_a_mentor_sees_only_the_requests_of_their_current_participants_in_the_sessions_they_mentor(): void
    {
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);
        $cy = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pat Participant']);
        $activity = Activity::factory()->create();
        $sessionX = TrainingSession::factory()->forWeek($activity->week)->withMentors($ana, $ben)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($activity->week)->withMentors($cy)->create(['title' => 'Session Y']);
        MentorAssignment::factory()->ended()->create(['training_session_id' => $sessionX->id, 'participant_id' => $participant->id, 'mentor_id' => $ana->id]);
        MentorAssignment::factory()->create(['training_session_id' => $sessionX->id, 'participant_id' => $participant->id, 'mentor_id' => $ben->id]);
        MentorAssignment::factory()->create(['training_session_id' => $sessionY->id, 'participant_id' => $participant->id, 'mentor_id' => $cy->id]);
        $inX = $this->pendingRequestInSession($participant, $activity, $sessionX);
        $inY = $this->pendingRequestInSession($participant, $activity, $sessionY);

        $this->actingAs($ben)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="'.$sessionX->id.'" >'.e($sessionX->titleWithDates()).'</option>',
                'Pat Participant · Attempt 1', 'Training session:', e($sessionX->titleWithDates()),
                'action="'.route('coach.recovery.decide', $inX).'"',
            ], false)
            ->assertDontSee(route('coach.recovery.decide', $inY), false)
            ->assertDontSee(e($sessionY->titleWithDates()), false);

        $this->actingAs($cy)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('action="'.route('coach.recovery.decide', $inY).'"', false)
            ->assertDontSee(route('coach.recovery.decide', $inX), false);

        $this->actingAs($ana)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('No pending recovery requests.')
            ->assertDontSee('Pat Participant');
    }

    public function test_the_session_filter_narrows_the_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['name' => 'Pat Participant']);
        $activity = Activity::factory()->create();
        $sessionX = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session Y']);
        $inX = $this->pendingRequestInSession($participant, $activity, $sessionX);
        $inY = $this->pendingRequestInSession($participant, $activity, $sessionY);

        $this->actingAs($admin)->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee(route('coach.recovery.decide', $inX), false)
            ->assertSee(route('coach.recovery.decide', $inY), false);

        $this->actingAs($admin)->get(route('coach.recovery.index', ['session' => $sessionY->id]))
            ->assertOk()
            ->assertSee('<option value="'.$sessionY->id.'" selected>'.e($sessionY->titleWithDates()).'</option>', false)
            ->assertSee('<option value="'.$sessionX->id.'" >'.e($sessionX->titleWithDates()).'</option>', false)
            ->assertSee('action="'.route('coach.recovery.decide', $inY).'"', false)
            ->assertDontSee(route('coach.recovery.decide', $inX), false)
            ->assertSee('href="'.route('coach.recovery.index').'"', false);

        $inY->update(['status' => 'declined']);

        $this->actingAs($admin)->get(route('coach.recovery.index', ['session' => $sessionY->id]))
            ->assertOk()
            ->assertSee('No pending recovery requests in this training session.');
    }

    public function test_an_unknown_session_filter_fails_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.recovery.index', ['session' => 999]))
            ->assertSessionHasErrors(['session']);
    }

    public function test_page_without_pending_requests_shows_a_notice_instead_of_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.recovery.index'))
            ->assertOk()
            ->assertSee('No pending recovery requests.')
            ->assertDontSee('aria-label="Pending recovery requests"', false);
    }

    public function test_a_decision_returns_to_the_page_with_the_saved_status(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $recoveryRequest = $this->recoveryRequest($coach, Activity::factory()->create(), 'Ada Approved', 1, null, now());

        $this->actingAs($coach)
            ->from(route('coach.recovery.index'))
            ->followingRedirects()
            ->post(route('coach.recovery.decide', $recoveryRequest), ['decision' => 'approved'])
            ->assertOk()
            ->assertSeeInOrder(['>Recovery requests</h1>', 'role="status"', 'Recovery request updated.'], false);
    }

    public function test_a_rejected_decision_returns_to_the_page_with_the_error(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $recoveryRequest = $this->recoveryRequest($coach, Activity::factory()->create(), 'Rita Rejected', 1, null, now());

        $this->actingAs($coach)
            ->from(route('coach.recovery.index'))
            ->followingRedirects()
            ->post(route('coach.recovery.decide', $recoveryRequest), ['decision' => 'approved', 'coach_response' => str_repeat('a', 2001)])
            ->assertOk()
            ->assertSeeInOrder(['>Recovery requests</h1>', 'role="alert"', 'The mentor response field must not be greater than 2000 characters.', 'Rita Rejected · Attempt 1'], false);

        $this->assertTrue($recoveryRequest->fresh()->isPending());
    }

    public function test_page_query_count_does_not_grow_with_the_number_of_requests(): void
    {
        $this->assertSame($this->recoveryRequestPageQueryCount(1), $this->recoveryRequestPageQueryCount(3));
    }

    /**
     * Create a recovery request with the given status, message and creation time for a failed attempt of the
     * activity by a new participant with the given name, whose current mentor in the attempt's session is the given
     * coach.
     */
    private function recoveryRequest(User $assignedCoach, Activity $activity, string $participantName, int $attemptNumber, ?string $message, CarbonInterface $createdAt, string $status = 'pending'): ActivityRecoveryRequest
    {
        $submission = ActivitySubmission::factory()
            ->for($activity)
            ->for(User::factory()->state(['name' => $participantName]), 'participant')
            ->create(['status' => 'failed', 'attempt_number' => $attemptNumber]);
        MentorAssignment::factory()->create(['training_session_id' => $submission->training_session_id, 'participant_id' => $submission->user_id, 'mentor_id' => $assignedCoach->id]);

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
     * Create a pending recovery request for the participant's failed first attempt at the activity in the session.
     */
    private function pendingRequestInSession(User $participant, Activity $activity, TrainingSession $trainingSession): ActivityRecoveryRequest
    {
        $submission = ActivitySubmission::factory()
            ->for($activity)
            ->for($participant, 'participant')
            ->create(['training_session_id' => $trainingSession->id, 'status' => 'failed', 'attempt_number' => 1]);

        return ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $submission->id, 'requested_at' => now()]);
    }

    /**
     * Create the given number of pending requests, each for a new activity in a new week, and count the queries of the
     * assigned coach's page request after a warm-up request.
     */
    private function recoveryRequestPageQueryCount(int $requestCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (range(1, $requestCount) as $index) {
            $this->recoveryRequest($coach, Activity::factory()->create(), "Participant {$index}", 1, null, now());
        }

        $this->actingAs($coach)->get(route('coach.recovery.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.recovery.index'))->assertOk()->assertSee('Approve Retry');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
