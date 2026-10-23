<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    public function test_the_current_mentor_and_admins_see_a_mentee_who_handed_in_work_for_the_incomplete_week_after_its_review(): void
    {
        ['session' => $session, 'weekOne' => $weekOne, 'weekOneActivity' => $weekOneActivity, 'pat' => $pat, 'ben' => $ben] = $this->waitingForVerificationFixture();
        $this->handInVersion($session, $weekOneActivity, $pat, '2026-11-15 08:00:00');
        $reviewUrl = route('training.coach.gates.show', [$session, $weekOne, $pat, $weekOne->gates()->sole()]);

        foreach ([$ben, User::factory()->create(['role' => 'admin'])] as $viewer) {
            $response = $this->actingAs($viewer)
                ->get(route('coach.dashboard'))
                ->assertOk()
                ->assertSeeInOrder([
                    'id="waiting-verification-title"', 'Waiting for verification',
                    '>Pat Participant</h3>',
                    'Session Z ('.$session->dateRangeLabel().')', 'Week 1',
                    'Latest submission', '<time datetime="'.Date::parse('2026-11-15 08:00:00')->toIso8601String().'"', '>Nov 15, 2026 · 8:00 AM</time>',
                    'href="'.$reviewUrl.'"', 'Review Week 1<span class="sr-only"> of Pat Participant</span>',
                ], false)
                ->assertDontSee('No participant is waiting for a week to be verified.');

            $this->assertSame(1, substr_count($response->getContent(), 'data-waiting-verification'));
        }
    }

    public function test_an_admin_sees_every_mentors_waiting_mentees_the_longest_waiting_first(): void
    {
        ['session' => $session, 'weekOne' => $weekOne, 'weekOneActivity' => $weekOneActivity, 'pat' => $pat, 'rae' => $rae] = $this->waitingForVerificationFixture();
        GateEvaluation::factory()->for($weekOne->gates()->sole(), 'gate')->for($session)->for($rae, 'participant')->create([
            'status' => GateEvaluationStatus::NeedsRevision,
            'evaluated_at' => '2026-11-14 10:00:00',
        ]);
        $this->handInVersion($session, $weekOneActivity, $pat, '2026-11-15 08:00:00');
        $this->handInVersion($session, $weekOneActivity, $rae, '2026-11-14 12:00:00');

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>Rae Else</h3>', '>Nov 14, 2026 · 12:00 PM</time>',
                '>Pat Participant</h3>', '>Nov 15, 2026 · 8:00 AM</time>',
            ], false);

        $this->assertSame(2, substr_count($response->getContent(), 'data-waiting-verification'));
    }

    public function test_a_mentee_is_not_waiting_for_verification_before_handing_in_work_after_the_review(): void
    {
        ['session' => $session, 'weekOneActivity' => $weekOneActivity, 'pat' => $pat, 'ben' => $ben] = $this->waitingForVerificationFixture();

        $this->assertNoneWaitingForVerification($ben);

        $this->handInVersion($session, $weekOneActivity, $pat, '2026-11-14 09:59:59');

        $this->assertNoneWaitingForVerification($ben);
    }

    public function test_other_and_previous_mentors_do_not_see_a_mentee_waiting_for_verification(): void
    {
        ['session' => $session, 'weekOneActivity' => $weekOneActivity, 'pat' => $pat, 'ana' => $ana, 'dan' => $dan] = $this->waitingForVerificationFixture();
        $this->handInVersion($session, $weekOneActivity, $pat, '2026-11-15 08:00:00');

        $this->assertNoneWaitingForVerification($ana);
        $this->assertNoneWaitingForVerification($dan);
    }

    public function test_a_mentee_stops_waiting_for_verification_once_the_mentor_marks_the_week_completed(): void
    {
        Queue::fake();
        ['session' => $session, 'weekOne' => $weekOne, 'weekOneActivity' => $weekOneActivity, 'criterion' => $criterion, 'pat' => $pat, 'ben' => $ben] = $this->waitingForVerificationFixture();
        $this->handInVersion($session, $weekOneActivity, $pat, '2026-11-15 08:00:00');
        $this->actingAs($ben)->get(route('coach.dashboard'))->assertOk()->assertSee('data-waiting-verification', false);

        $this->actingAs($ben)->post(route('training.coach.gates.update', [$session, $weekOne, $pat, $weekOne->gates()->sole()]), [
            'status' => GateEvaluationStatus::Passed->value,
            'criteria' => [$criterion->id => GateCriterionStatus::Met->value],
        ])->assertSessionHasNoErrors();

        $this->assertNoneWaitingForVerification($ben);
    }

    public function test_query_count_does_not_grow_with_mentees_waiting_for_verification(): void
    {
        $this->assertSame($this->waitingForVerificationQueryCount(1), $this->waitingForVerificationQueryCount(3));
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
     * Assert that the viewer's dashboard shows the "Waiting for verification" card with its empty text and no row.
     */
    private function assertNoneWaitingForVerification(User $viewer): void
    {
        $this->actingAs($viewer)
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Waiting for verification', 'No participant is waiting for a week to be verified.'])
            ->assertDontSee('data-waiting-verification', false)
            ->assertDontSee('Pat Participant');
    }

    /**
     * A two-week session "Session Z" (Mon 9 to Sun 22 Nov 2026) whose Week 1 has an activity and a one-item checklist.
     * An admin assigned Ana and then Ben as Pat's mentor there, Dan mentors Rae there, and Ben marked Pat's Week 1
     * incomplete on Sat 14 Nov 2026 at 10:00 (the item not met), which locks Week 2. The clock is Mon 16 Nov 2026, 09:00.
     *
     * @return array{session: TrainingSession, weekOne: TrainingWeek, weekOneActivity: Activity, criterion: GateCriterion, pat: User, rae: User, ana: User, ben: User, dan: User}
     */
    private function waitingForVerificationFixture(): array
    {
        $this->travelTo('2026-11-16 09:00:00');
        $program = Program::factory()->create();
        $weekOne = TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1]);
        TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $weekOneActivity = Activity::factory()->for($weekOne, 'week')->create();
        $gate = TrainingGate::factory()->for($weekOne, 'week')->create();
        $criterion = GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
        $session = TrainingSession::factory()->for($program)->create(['title' => 'Session Z', 'weeks_count' => 2, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-22']);
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $rae = User::factory()->create(['name' => 'Rae Else']);
        [$ana, $ben, $dan] = User::factory()->count(3)->create(['role' => 'coach'])->all();
        $this->mentor($session, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($session, $ben, $pat);
        $this->mentor($session, $dan, $rae);
        $review = GateEvaluation::factory()->for($gate, 'gate')->for($session)->for($pat, 'participant')->create([
            'status' => GateEvaluationStatus::NeedsRevision,
            'reviewer_id' => $ben->id,
            'evaluated_at' => '2026-11-14 10:00:00',
        ]);
        GateCriterionEvaluation::factory()->for($review, 'gateEvaluation')->for($criterion, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);

        return compact('session', 'weekOne', 'weekOneActivity', 'criterion', 'pat', 'rae', 'ana', 'ben', 'dan');
    }

    /**
     * A version of the participant's attempt at the activity in the session, handed in at the given time.
     */
    private function handInVersion(TrainingSession $session, Activity $activity, User $participant, CarbonInterface|string $submittedAt): SubmissionVersion
    {
        return SubmissionVersion::factory()
            ->for(ActivitySubmission::factory()->for($session)->for($activity)->for($participant, 'participant')->create(['status' => 'submitted', 'submitted_at' => $submittedAt]), 'submission')
            ->create(['status' => 'submitted', 'submitted_at' => $submittedAt]);
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

        ['queries' => $queries, 'html' => $html] = $this->measureDashboard($coach);
        $this->assertSame($sessionCount, substr_count($html, 'data-my-session'));

        return $queries;
    }

    /**
     * Create a coach who mentors one new participant in each of the given number of new sessions, each of another
     * program, each participant having handed in work for the week of their incomplete review since it, and count the
     * queries of that coach's dashboard request after a warm-up request.
     */
    private function waitingForVerificationQueryCount(int $mentees): int
    {
        $coach = User::factory()->create(['role' => 'coach']);

        foreach (range(1, $mentees) as $index) {
            $activity = Activity::factory()->create();
            $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
            $session = TrainingSession::factory()->forWeek($activity->week)->create();
            $participant = User::factory()->create();
            $this->mentor($session, $coach, $participant);
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->for($session)->create(['status' => 'needs_revision', 'evaluated_at' => now()->subDay()]);
            $this->handInVersion($session, $activity, $participant, now()->subHour());
        }

        ['queries' => $queries, 'html' => $html] = $this->measureDashboard($coach);
        $this->assertSame($mentees, substr_count($html, 'data-waiting-verification'));

        return $queries;
    }

    /**
     * Request the viewer's dashboard once to warm up, then again while counting its queries.
     *
     * @return array{queries: int, html: string}
     */
    private function measureDashboard(User $viewer): array
    {
        $this->actingAs($viewer)->get(route('coach.dashboard'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->actingAs($viewer)->get(route('coach.dashboard'))->assertOk()->getContent();
        DB::disableQueryLog();

        return ['queries' => count(DB::getQueryLog()), 'html' => $html];
    }
}
