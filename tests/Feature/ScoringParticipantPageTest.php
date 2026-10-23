<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScoringParticipantPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_mentor_sees_the_overall_and_weekly_scores_in_the_session_with_a_row_and_action_per_activity(): void
    {
        $program = Program::factory()->create();
        $weekTwo = $this->week($program, 2);
        $weekThree = $this->week($program, 3);
        $this->week($program, 4);
        $session = TrainingSession::factory()->forWeek($weekTwo)->create(['title' => 'November training']);
        $scored = Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Exploratory charter', 'position' => 1]);
        $unscored = Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Bug report', 'position' => 2]);
        $drafted = Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Draft notes', 'position' => 3]);
        Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Untouched task', 'position' => 4]);
        Activity::factory()->for($weekThree, 'week')->create(['title' => 'Regression pass', 'position' => 1]);
        $participant = User::factory()->create(['name' => 'Ada Automation', 'email' => 'ada@example.com']);
        $scoredVersion = $this->version($participant, $scored, 'approved');
        $this->publishedScore($scoredVersion, 83, 'Proficient');
        $unscoredVersion = $this->version($participant, $unscored, 'submitted');
        $draftVersion = $this->version($participant, $drafted, 'draft');

        $response = $this->actingAs($this->mentor($session, $participant))
            ->get(route('coach.scoring.participant', [$session, $participant]))
            ->assertOk();

        $response->assertSeeInOrder([
            'SCORING · PARTICIPANT',
            '>Ada Automation</h1>',
            'ada@example.com</p>',
            '<strong>Training session:</strong> '.$session->titleWithDates().'</p>',
            'OVERALL SCORE</dt>', '>83 / 100</dd>',
            'OVERALL COMPLETION</dt>', '>2 / 5</dd>', '>40% complete · 1 scored</dd>',
            '>WEEK 2</div>', '>83 / 100 average</h2>', 'Completion: 2 / 4 (50%) · Scored: 1 / 4',
            '>Exploratory charter</th>', '>83</td>', '>Proficient</td>', '>approved</td>',
            'href="'.route('coach.scoring.edit', $scoredVersion).'"', '>View score</a>',
            '>Bug report</th>', '>—</td>', '>Awaiting scoring</td>', '>submitted</td>',
            'href="'.route('coach.scoring.edit', $unscoredVersion).'"', '>Score activity</a>',
            '>Draft notes</th>', '>—</td>', '>Awaiting scoring</td>', '>draft</td>', '<span class="text-muted">—</span>',
            '>Untouched task</th>', '>—</td>', '>Not submitted</td>', '>Not submitted</td>', '<span class="text-muted">—</span>',
            '>WEEK 3</div>', '>Average not yet scored</h2>', 'Completion: 0 / 1 (0%) · Scored: 0 / 1',
            '>Regression pass</th>', '>Not submitted</td>',
            '>WEEK 4</div>', '>Average not yet scored</h2>', 'Completion: 0 / 0 (0%) · Scored: 0 / 0',
            'No active activities in this week.',
        ], false);
        $response->assertDontSee(route('coach.scoring.edit', $draftVersion), false)
            ->assertDontSee('from-brand to-brand-deep', false)
            ->assertDontSee('This training session has no weeks to score yet.');
        $this->assertSame(1, substr_count($response->getContent(), 'No active activities in this week.'));
    }

    public function test_the_page_shows_only_the_work_in_its_session(): void
    {
        $week = $this->week(Program::factory()->create(), 2);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Exploratory charter', 'position' => 1]);
        [$sessionX, $sessionY] = TrainingSession::factory()->forWeek($week)->count(2)->create()->all();
        $participant = User::factory()->create();
        $versionX = $this->version($participant, $activity, 'submitted', $sessionX);
        $this->publishedScore($versionX, 83, 'Proficient');
        $versionY = $this->version($participant, $activity, 'submitted', $sessionY);
        $this->publishedScore($versionY, 41, 'Beginning');

        $this->actingAs($this->mentor($sessionY, $participant))
            ->get(route('coach.scoring.participant', [$sessionY, $participant]))
            ->assertOk()
            ->assertSeeInOrder(['>41 / 100</dd>', '>Exploratory charter</th>', '>41</td>', 'href="'.route('coach.scoring.edit', $versionY).'"'], false)
            ->assertDontSee('>83', false)
            ->assertDontSee(route('coach.scoring.edit', $versionX), false);
    }

    public function test_only_an_admin_or_the_participants_current_mentor_in_the_session_opens_the_page(): void
    {
        $week = $this->week(Program::factory()->create(), 2);
        [$sessionX, $sessionY] = TrainingSession::factory()->forWeek($week)->count(2)->create()->all();
        $participant = User::factory()->create();
        $previousMentor = $this->mentor($sessionX, $participant);
        MentorAssignment::query()->update(['ended_at' => now()]);
        $currentMentor = $this->mentor($sessionX, $participant);
        $otherSessionMentor = $this->mentor($sessionY, $participant);
        $url = route('coach.scoring.participant', [$sessionX, $participant]);

        foreach ([$previousMentor, $otherSessionMentor, User::factory()->create(['role' => 'coach'])] as $coach) {
            $this->actingAs($coach)->get($url)->assertForbidden();
        }
        $this->actingAs($currentMentor)->get($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url)->assertOk();
        $this->actingAs($participant)->get($url)->assertForbidden();
    }

    public function test_a_participant_who_is_not_in_the_session_is_not_found(): void
    {
        $session = TrainingSession::factory()->forWeek($this->week(Program::factory()->create(), 2))->create();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.scoring.participant', [$session, User::factory()->create()]))
            ->assertNotFound();
    }

    public function test_activity_row_scores_the_latest_submitted_version_rather_than_a_newer_revision_draft(): void
    {
        $program = Program::factory()->create();
        $activity = Activity::factory()->for($this->week($program, 2), 'week')->create(['title' => 'Exploratory charter', 'position' => 1]);
        $participant = User::factory()->create();
        $submittedVersion = $this->version($participant, $activity, 'submitted');
        $draftVersion = SubmissionVersion::factory()->for($submittedVersion->submission, 'submission')->create(['version_number' => 2, 'status' => 'draft']);
        $session = $submittedVersion->submission->trainingSession;

        $this->actingAs($this->mentor($session, $participant))
            ->get(route('coach.scoring.participant', [$session, $participant]))
            ->assertOk()
            ->assertSee('href="'.route('coach.scoring.edit', $submittedVersion).'"', false)
            ->assertDontSee(route('coach.scoring.edit', $draftVersion), false);
    }

    public function test_a_draft_score_shows_as_unscored(): void
    {
        $program = Program::factory()->create();
        $activity = Activity::factory()->for($this->week($program, 2), 'week')->create(['title' => 'Exploratory charter', 'position' => 1]);
        $participant = User::factory()->create();
        $version = $this->version($participant, $activity, 'submitted');
        ActivityScore::create([
            'submission_version_id' => $version->id,
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => 'draft',
            'total_score' => 41,
            'performance_band' => 'Beginning',
        ]);
        $session = $version->submission->trainingSession;

        $this->actingAs($this->mentor($session, $participant))
            ->get(route('coach.scoring.participant', [$session, $participant]))
            ->assertOk()
            ->assertSeeInOrder([
                'OVERALL SCORE</dt>', '>— / 100</dd>', '>100% complete · 0 scored</dd>',
                '>Average not yet scored</h2>', 'Scored: 0 / 1',
                '>Exploratory charter</th>', '>—</td>', '>Awaiting scoring</td>',
                'href="'.route('coach.scoring.edit', $version).'"', '>Score activity</a>',
            ], false)
            ->assertDontSee('>41</td>', false)
            ->assertDontSee('Beginning')
            ->assertDontSee('>View score</a>', false);
    }

    public function test_a_saved_score_returns_to_the_page_with_the_saved_status(): void
    {
        Queue::fake();
        $activity = Activity::factory()->for($this->week(Program::factory()->create(), 2), 'week')->withScoringDimensions()->create();
        $participant = User::factory()->create();
        $version = $this->version($participant, $activity, 'submitted');
        $coach = $this->mentor($version->submission->trainingSession, $participant);
        $dimensions = collect($activity->scoringDimensions())->mapWithKeys(fn (array $dimension): array => [$dimension['key'] => ['points' => 1, 'evidence_reasoning' => 'Reason']])->all();

        $this->actingAs($coach)->followingRedirects()
            ->post(route('coach.scoring.store', $version), ['dimensions' => $dimensions])
            ->assertOk()
            ->assertSeeInOrder(['SCORING · PARTICIPANT', 'role="status"', 'Activity score saved.', 'OVERALL SCORE</dt>'], false)
            ->assertSee('<strong>Training session:</strong> '.$version->submission->trainingSession->titleWithDates().'</p>', false);
    }

    public function test_a_user_who_is_not_a_participant_is_not_found(): void
    {
        $session = TrainingSession::factory()->forWeek($this->week(Program::factory()->create(), 2))->create();
        $coachInSession = User::factory()->create(['role' => 'coach']);
        $session->participants()->attach($coachInSession, ['joined_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.scoring.participant', [$session, $coachInSession]))
            ->assertNotFound();
    }

    public function test_a_session_without_active_weeks_says_so_and_shows_no_weeks(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1, 'is_active' => false]);
        $session = TrainingSession::factory()->forWeek($week)->create();
        $participant = User::factory()->create();

        $this->actingAs($this->mentor($session, $participant))
            ->get(route('coach.scoring.participant', [$session, $participant]))
            ->assertOk()
            ->assertSeeInOrder(['This training session has no weeks to score yet.', 'OVERALL COMPLETION', '0 / 0'])
            ->assertDontSee('WEEK 1');
    }

    public function test_query_count_does_not_grow_with_the_number_of_activities(): void
    {
        $this->assertSame($this->participantPageQueryCount(1), $this->participantPageQueryCount(3));
    }

    private function week(Program $program, int $weekNumber): TrainingWeek
    {
        return TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
    }

    /**
     * Add the participant to the session (when not in it yet) with a new coach, made a session mentor, as their current
     * mentor there, and return that coach.
     */
    private function mentor(TrainingSession $session, User $participant): User
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $session->mentors()->attach($coach);
        $session->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        return $coach;
    }

    /**
     * A first version of the participant's submission of the activity, with the submission and version in the given
     * status, in the given session (by default the program's first session that includes the week).
     */
    private function version(User $participant, Activity $activity, string $status, ?TrainingSession $session = null): SubmissionVersion
    {
        $submittedAt = $status === 'draft' ? null : now();
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create([
            'status' => $status,
            'submitted_at' => $submittedAt,
            ...($session ? ['training_session_id' => $session->id] : []),
        ]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status, 'submitted_at' => $submittedAt]);
    }

    private function publishedScore(SubmissionVersion $version, int $total, string $band): void
    {
        ActivityScore::create([
            'submission_version_id' => $version->id,
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => 'published',
            'total_score' => $total,
            'performance_band' => $band,
        ]);
    }

    /**
     * Build a new program and session with the given number of activities in each of Weeks 2–4, each submitted and
     * scored by one participant, and count the queries of the participant scoring page for their mentor after a
     * warm-up request.
     */
    private function participantPageQueryCount(int $activityCount): int
    {
        $program = Program::factory()->create();
        $session = TrainingSession::factory()->forWeek($this->week($program, 1))->create();
        $participant = User::factory()->create();
        $coach = $this->mentor($session, $participant);

        foreach ([2, 3, 4] as $weekNumber) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);

            foreach (range(1, $activityCount) as $position) {
                $activity = Activity::factory()->for($week, 'week')->create(['position' => $position]);
                $this->publishedScore($this->version($participant, $activity, 'submitted'), 80, 'Proficient');
            }
        }

        $url = route('coach.scoring.participant', [$session, $participant]);
        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee('>80 / 100 average</h2>', false);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
