<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachDashboardPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_mentor_in_two_sessions_sees_both_and_counts_only_their_current_mentees(): void
    {
        ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'ben' => $ben] = $this->mentorScopeFixture();

        $response = $this->actingAs($ben)
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR OPERATIONS',
                'Training overview',
                '<dt', 'href="'.route('coach.participants').'" class="outline-hidden after:absolute after:inset-0">PARTICIPANTS<span class="sr-only">, open the participants list</span></a>', '<dd class="m-0 text-3xl font-black">2</dd>',
                '<dt class="text-xs font-black tracking-[0.12em] wrap-break-word text-muted uppercase">COMPLETED</dt>', '<dd class="m-0 text-3xl font-black">1</dd>',
                '<dt', 'href="'.route('coach.reviews').'" class="outline-hidden after:absolute after:inset-0">NEEDS REVIEW<span class="sr-only">, open the review queue</span></a>', '<dd class="m-0 text-3xl font-black">1</dd>',
                '<dt', 'href="'.route('coach.reviews', ['status' => 'needs_revision']).'" class="outline-hidden after:absolute after:inset-0">NEEDS REVISION<span class="sr-only">, open the review queue filtered to needs revision</span></a>', '<dd class="m-0 text-3xl font-black">1</dd>',
                '<dt', 'href="'.route('coach.reports').'#at-risk-title" class="outline-hidden after:absolute after:inset-0">AT RISK<span class="sr-only">, open the at-risk participants in the progress report</span></a>', '<dd class="m-0 text-3xl font-black">1</dd>',
                'id="my-sessions-title"', 'My sessions',
                '>Session Y</h3>', '>Upcoming</span>', $sessionY->dateRangeLabel(), '1 participant assigned to you',
                'href="'.e(route('coach.participants', ['session' => $sessionY->id])).'"', 'View participants<span class="sr-only"> of Session Y</span>',
                '>Session X</h3>', '>Active</span>', $sessionX->dateRangeLabel(), '1 participant assigned to you',
                'href="'.e(route('coach.participants', ['session' => $sessionX->id])).'"', 'View participants<span class="sr-only"> of Session X</span>',
            ], false)
            ->assertDontSee('No active program.')
            ->assertDontSee('You are not a mentor in any training session yet.')
            ->assertDontSee('aria-label="Coach tools"', false)
            ->assertDontSee('MENTOR INTERVIEW AVAILABILITY')
            ->assertDontSee('Manage Booking Calendar');

        $this->assertSame(2, substr_count($response->getContent(), 'data-my-session'));
    }

    public function test_a_previous_mentor_counts_none_of_the_participants_they_no_longer_mentor(): void
    {
        ['sessionX' => $sessionX, 'ana' => $ana] = $this->mentorScopeFixture();

        $this->actingAs($ana)
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANTS', '<dd class="m-0 text-3xl font-black">0</dd>',
                'COMPLETED', '<dd class="m-0 text-3xl font-black">0</dd>',
                'NEEDS REVIEW', '<dd class="m-0 text-3xl font-black">0</dd>',
                'NEEDS REVISION', '<dd class="m-0 text-3xl font-black">0</dd>',
                'AT RISK', '<dd class="m-0 text-3xl font-black">0</dd>',
                'My sessions', '>Session X</h3>', '0 participants assigned to you',
            ], false)
            ->assertDontSee('>Session Y</h3>', false);
    }

    public function test_a_coach_with_no_sessions_sees_zero_counts_and_the_empty_my_sessions_card(): void
    {
        $this->mentorScopeFixture();

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANTS', '<dd class="m-0 text-3xl font-black">0</dd>',
                'COMPLETED', '<dd class="m-0 text-3xl font-black">0</dd>',
                'NEEDS REVIEW', '<dd class="m-0 text-3xl font-black">0</dd>',
                'NEEDS REVISION', '<dd class="m-0 text-3xl font-black">0</dd>',
                'AT RISK', '<dd class="m-0 text-3xl font-black">0</dd>',
                'My sessions',
                'You are not a mentor in any training session yet.',
            ], false)
            ->assertDontSee('data-my-session', false);
    }

    public function test_admin_counts_every_membership_with_the_same_stat_links_and_no_my_sessions_card(): void
    {
        $this->mentorScopeFixture();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('coach.participants').'" class="outline-hidden after:absolute after:inset-0">PARTICIPANTS', '<dd class="m-0 text-3xl font-black">3</dd>',
                'COMPLETED</dt>', '<dd class="m-0 text-3xl font-black">2</dd>',
                'href="'.route('coach.reviews').'" class="outline-hidden after:absolute after:inset-0">NEEDS REVIEW', '<dd class="m-0 text-3xl font-black">1</dd>',
                'href="'.route('coach.reviews', ['status' => 'needs_revision']).'" class="outline-hidden after:absolute after:inset-0">NEEDS REVISION', '<dd class="m-0 text-3xl font-black">2</dd>',
                'href="'.route('coach.reports').'#at-risk-title" class="outline-hidden after:absolute after:inset-0">AT RISK', '<dd class="m-0 text-3xl font-black">2</dd>',
            ], false)
            ->assertDontSee('My sessions')
            ->assertDontSee('aria-label="Coach tools"', false)
            ->assertDontSee('Settings · Google Drive');
    }

    public function test_the_at_risk_link_target_exists_on_the_progress_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports'))
            ->assertOk()
            ->assertSee('id="at-risk-title"', false);
    }

    public function test_query_count_does_not_grow_with_the_mentors_sessions_and_mentees(): void
    {
        $this->assertSame($this->dashboardQueryCount(1), $this->dashboardQueryCount(3));
    }

    /**
     * A program whose only week has an activity and a Gate, and two sessions of it: Session X (active), where an admin
     * assigned Ana and then Ben as Pat's mentor and Dan mentors Rae, and Session Y (upcoming), where Ben mentors Quinn.
     * Pat passed the week and has a submission awaiting review in X, Rae passed it and needs revision in X, and Quinn
     * needs revision in Y.
     *
     * @return array{sessionX: TrainingSession, sessionY: TrainingSession, ana: User, ben: User}
     */
    private function mentorScopeFixture(): array
    {
        $activity = Activity::factory()->create();
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $sessionX = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($activity->week)->upcoming()->create(['title' => 'Session Y']);
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        $rae = User::factory()->create(['name' => 'Rae Else']);
        [$ana, $ben, $dan] = User::factory()->count(3)->create(['role' => 'coach'])->all();
        $this->mentor($sessionX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($sessionX, $ben, $pat);
        $this->mentor($sessionX, $dan, $rae);
        $this->mentor($sessionY, $ben, $quinn);

        foreach ([[$pat, $sessionX, 'submitted'], [$rae, $sessionX, 'needs_revision'], [$quinn, $sessionY, 'needs_revision']] as [$participant, $trainingSession, $status]) {
            ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['training_session_id' => $trainingSession->id, 'status' => $status, 'submitted_at' => now()]);
        }
        foreach ([$pat, $rae] as $participant) {
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->for($sessionX)->create(['status' => 'passed', 'evaluated_at' => now()]);
        }

        return ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'ana' => $ana, 'ben' => $ben];
    }

    /**
     * Add the coach to the session's mentors and the participants to the session, with the coach as their current
     * mentor there.
     */
    private function mentor(TrainingSession $session, User $coach, User ...$participants): void
    {
        $session->mentors()->syncWithoutDetaching([$coach->id]);
        foreach ($participants as $participant) {
            $session->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
            MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * Create a coach who mentors one new participant in each of the given number of new sessions, each participant
     * with a submission needing revision and a passed Gate evaluation there, and count the queries of that coach's
     * dashboard request after a warm-up request.
     */
    private function dashboardQueryCount(int $sessionCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (range(1, $sessionCount) as $index) {
            $activity = Activity::factory()->create();
            $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
            $session = TrainingSession::factory()->forWeek($activity->week)->create();
            $participant = User::factory()->create();
            $this->mentor($session, $coach, $participant);
            ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['training_session_id' => $session->id, 'status' => 'needs_revision']);
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->for($session)->create(['status' => 'passed']);
        }

        $this->actingAs($coach)->get(route('coach.dashboard'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($coach)->get(route('coach.dashboard'))->assertOk();
        DB::disableQueryLog();
        $this->assertSame($sessionCount, substr_count($response->getContent(), 'data-my-session'));

        return count(DB::getQueryLog());
    }
}
