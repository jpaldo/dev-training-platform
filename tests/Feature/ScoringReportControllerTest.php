<?php

namespace Tests\Feature;

use App\Enums\ActivityScoreStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScoringReportControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participant_summary_query_count_does_not_grow_with_participants_and_activities(): void
    {
        $singleRowQueryCount = $this->participantSummaryQueryCount(1);

        $threeRowQueryCount = $this->participantSummaryQueryCount(3);

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    public function test_participant_summary_reports_each_participants_own_latest_attempt_scores(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Casey Coach']);
        $program = Program::factory()->create();
        $weekTwo = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $weekThree = TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]);
        $firstActivity = Activity::factory()->for($weekTwo, 'week')->create(['position' => 1]);
        $secondActivity = Activity::factory()->for($weekTwo, 'week')->create(['position' => 2]);
        $weekThreeActivity = Activity::factory()->for($weekThree, 'week')->create(['position' => 1]);
        $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'employee_id' => 'EMP-1001']);
        $ben = User::factory()->create(['name' => 'Ben', 'email' => 'ben@example.com', 'employee_id' => null]);
        $session = TrainingSession::factory()->forWeek($weekTwo)->create(['title' => 'November training']);
        $this->mentor($session, $coach, $ada);
        $this->join($session, $ben);

        $this->createScoredSubmission($ada, $firstActivity, $coach, 80);
        $this->createScoredSubmission($ada, $secondActivity, $coach, 90);
        $this->createScoredSubmission($ben, $firstActivity, $coach, 60);
        $this->createScoredSubmission($ben, $weekThreeActivity, $coach, 20);
        $this->travel(1)->hour();
        $this->createScoredSubmission($ben, $weekThreeActivity, $coach, 70, attemptNumber: 2);

        $csv = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports.participant-summary'))->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $rows = collect($lines)->skip(1)->map(fn (string $line): array => str_getcsv($line))->keyBy(0);
        $sessionLabel = $session->titleWithDates();

        $this->assertSame(['Participant', 'Employee ID', 'Training session', 'Email', 'Assigned Mentor', 'Completed Activities', 'Scored Activities', 'Unscored Activities', 'Week 2 Average', 'Week 3 Average', 'Week 4 Average', 'Overall Average', 'Current Performance Band', 'Latest Score Date'], str_getcsv($lines[0]));
        $this->assertCount(2, $rows);
        $this->assertSame(['Ada', 'EMP-1001', $sessionLabel, 'ada@example.com', 'Casey Coach', '2', '2', '0', '85', '', '', '85', 'Proficient', '2026-10-01 09:00:00'], $rows['Ada']);
        $this->assertSame(['Ben', '', $sessionLabel, 'ben@example.com', '', '2', '2', '0', '60', '70', '', '65', 'Developing', '2026-10-01 10:00:00'], $rows['Ben']);
    }

    public function test_participant_summary_counts_unscored_activities_only_among_completed_ones(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        [$scoredActivity, $revisionActivity, $unscoredActivity] = Activity::factory()->for($week, 'week')->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create()->all();
        $participant = User::factory()->create(['name' => 'Ada']);
        $this->mentor(TrainingSession::factory()->forWeek($week)->create(), $coach, $participant);
        $this->createScoredSubmission($participant, $scoredActivity, $coach, 80);
        $this->createScoredSubmission($participant, $revisionActivity, $coach, 60, submissionStatus: 'needs_revision');
        $unscoredSubmission = ActivitySubmission::factory()->for($unscoredActivity)->for($participant, 'participant')->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($unscoredSubmission, 'submission')->create(['status' => 'submitted']);

        $csv = $this->actingAs($coach)->get(route('coach.reports.participant-summary'))->assertOk()->streamedContent();
        $row = str_getcsv(explode("\n", trim($csv))[1]);

        $this->assertSame(['Ada', '2', '2', '1'], [$row[0], $row[5], $row[6], $row[7]]);
    }

    public function test_participant_summary_counts_a_draft_score_as_unscored_and_leaves_it_out_of_the_latest_score_date(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        [$scoredActivity, $draftActivity] = Activity::factory()->for($week, 'week')->count(2)->sequence(['position' => 1], ['position' => 2])->create()->all();
        $participant = User::factory()->create(['name' => 'Ada']);
        $this->mentor(TrainingSession::factory()->forWeek($week)->create(), $coach, $participant);
        $this->createScoredSubmission($participant, $scoredActivity, $coach, 80);
        $this->travel(1)->hour();
        $this->createScoredSubmission($participant, $draftActivity, $coach, 40, status: ActivityScoreStatus::Draft);

        $csv = $this->actingAs($coach)->get(route('coach.reports.participant-summary'))->assertOk()->streamedContent();
        $row = str_getcsv(explode("\n", trim($csv))[1]);

        $this->assertSame(['Ada', '2', '1', '1', '80', '80', '2026-10-01 09:00:00'], [$row[0], $row[5], $row[6], $row[7], $row[8], $row[11], $row[13]]);
    }

    public function test_activity_scores_export_prints_a_draft_score_as_unscored(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Casey Coach']);
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $participant = User::factory()->create(['employee_id' => 'EMP-2001']);
        $published = Activity::factory()->for($week, 'week')->create(['title' => 'Published activity', 'position' => 1]);
        $draft = Activity::factory()->for($week, 'week')->create(['title' => 'Draft activity', 'position' => 2]);
        $unscored = Activity::factory()->for($week, 'week')->create(['title' => 'Unscored activity', 'position' => 3]);
        $session = TrainingSession::factory()->forWeek($week)->create();
        $this->mentor($session, $coach, $participant);

        $this->createScoredSubmission($participant, $published, $coach, 83);
        $this->createScoredSubmission($participant, $draft, $coach, 41, status: ActivityScoreStatus::Draft);
        $unscoredSubmission = ActivitySubmission::factory()->for($unscored)->for($participant, 'participant')->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($unscoredSubmission, 'submission')->create(['status' => 'submitted']);

        $csv = $this->actingAs($coach)->get(route('coach.reports.scores'))->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $rows = collect($lines)->skip(1)->map(fn (string $line): array => str_getcsv($line));
        $statuses = $rows->mapWithKeys(fn (array $row): array => [$row[5] => [$row[8], $row[14], $row[15], $row[16]]]);

        $this->assertSame(['Participant', 'Employee ID', 'Training session', 'Email', 'Week', 'Activity', 'Attempt/Version', 'Submission Status', 'Scoring Status', 'D1', 'D2', 'D3', 'D4', 'D5', 'Total Score', 'Performance Band', 'Scorer', 'Scored At', 'Second Reader', 'Second Reader Status', 'Assigned Mentor'], str_getcsv($lines[0]));
        $this->assertSame(['EMP-2001'], $rows->pluck(1)->unique()->values()->all());
        $this->assertSame([$session->titleWithDates()], $rows->pluck(2)->unique()->values()->all());
        $this->assertSame(['Casey Coach'], $rows->pluck(20)->unique()->values()->all());

        $this->assertSame(['Published activity' => ['published', '83', 'Proficient', 'Casey Coach'], 'Draft activity' => ['unscored', '', '', ''], 'Unscored activity' => ['unscored', '', '', '']], $statuses->all());
    }

    public function test_the_exports_list_only_a_mentors_current_mentees_per_session_and_everything_for_an_admin(): void
    {
        ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan] = $this->mentorScopeFixture();
        $labelX = $sessionX->titleWithDates();
        $labelY = $sessionY->titleWithDates();

        $this->assertSame([['Pat', $labelX, '83', 'Ben Current']], $this->scoreRows($ben));
        $this->assertSame([['Pat', $labelX, 'Ben Current', '83']], $this->summaryRows($ben));
        $this->assertSame([['Pat', $labelY, '60', 'Cy Other']], $this->scoreRows($cy));
        $this->assertSame([['Pat', $labelY, 'Cy Other', '60']], $this->summaryRows($cy));
        $this->assertSame([], $this->scoreRows($ana));
        $this->assertSame([], $this->summaryRows($ana));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertEqualsCanonicalizing([['Pat', $labelX, '83', 'Ben Current'], ['Pat', $labelY, '60', 'Cy Other'], ['Quinn', $labelX, '70', 'Dan Quinns']], $this->scoreRows($admin));
        $this->assertSame([['Pat', $labelY, 'Cy Other', '60'], ['Pat', $labelX, 'Ben Current', '83'], ['Quinn', $labelX, 'Dan Quinns', '70']], $this->summaryRows($admin));
        $this->assertSame([['Pat', $labelY, '60', 'Cy Other']], $this->scoreRows($admin, ['session' => $sessionY->id]));
        $this->assertSame([['Pat', $labelY, 'Cy Other', '60']], $this->summaryRows($admin, ['session' => $sessionY->id]));
        $this->assertSame([['Quinn', $labelX, '70', 'Dan Quinns']], $this->scoreRows($admin, ['mentor' => $dan->id]));
        $this->assertSame([['Quinn', $labelX, 'Dan Quinns', '70']], $this->summaryRows($admin, ['mentor' => $dan->id]));
    }

    public function test_an_unknown_session_or_a_mentor_who_is_not_a_coach_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $filters = ['session' => 999, 'mentor' => User::factory()->create()->id];

        foreach (['coach.reports.scores', 'coach.reports.participant-summary'] as $routeName) {
            $this->actingAs($admin)->get(route($routeName, $filters))->assertSessionHasErrors(['session', 'mentor']);
        }
    }

    /**
     * A Week 2 activity and two sessions of its program. In session X an admin assigned Ana and then Ben as Pat's
     * mentor, and Dan mentors Quinn; in session Y Cy mentors Pat. Pat scored 83 in X and 60 in Y, Quinn 70 in X.
     *
     * @return array{sessionX: TrainingSession, sessionY: TrainingSession, ana: User, ben: User, cy: User, dan: User}
     */
    private function mentorScopeFixture(): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $sessionX = TrainingSession::factory()->forWeek($week)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($week)->create(['title' => 'Session Y', 'starts_on' => today()->addWeek(), 'ends_on' => today()->addWeeks(5)->subDay()]);
        $pat = User::factory()->create(['name' => 'Pat']);
        $quinn = User::factory()->create(['name' => 'Quinn']);
        $ana = User::factory()->create(['role' => 'coach', 'name' => 'Ana Earlier']);
        $ben = User::factory()->create(['role' => 'coach', 'name' => 'Ben Current']);
        $cy = User::factory()->create(['role' => 'coach', 'name' => 'Cy Other']);
        $dan = User::factory()->create(['role' => 'coach', 'name' => 'Dan Quinns']);
        $this->mentor($sessionX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($sessionX, $ben, $pat);
        $this->mentor($sessionX, $dan, $quinn);
        $this->mentor($sessionY, $cy, $pat);
        $this->createScoredSubmission($pat, $activity, $ben, 83, session: $sessionX);
        $this->createScoredSubmission($pat, $activity, $cy, 60, session: $sessionY);
        $this->createScoredSubmission($quinn, $activity, $dan, 70, session: $sessionX);

        return ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan];
    }

    /**
     * The participant, training session, total score and mentor of each row of the viewer's activity scores CSV.
     *
     * @param  array<string, int>  $filters
     * @return list<list<string>>
     */
    private function scoreRows(User $viewer, array $filters = []): array
    {
        $lines = explode("\n", trim($this->actingAs($viewer)->get(route('coach.reports.scores', $filters))->assertOk()->streamedContent()));

        return collect($lines)->skip(1)->map(fn (string $line): array => str_getcsv($line))->map(fn (array $row): array => [$row[0], $row[2], $row[14], $row[20]])->values()->all();
    }

    /**
     * The participant, training session, mentor and overall average of each row of the viewer's participant summary
     * CSV.
     *
     * @param  array<string, int>  $filters
     * @return list<list<string>>
     */
    private function summaryRows(User $viewer, array $filters = []): array
    {
        $lines = explode("\n", trim($this->actingAs($viewer)->get(route('coach.reports.participant-summary', $filters))->assertOk()->streamedContent()));

        return collect($lines)->skip(1)->map(fn (string $line): array => str_getcsv($line))->map(fn (array $row): array => [$row[0], $row[2], $row[4], $row[11]])->values()->all();
    }

    /**
     * Add the coach to the session's mentors and the participants to the session, with the coach as their current
     * mentor there.
     */
    private function mentor(TrainingSession $session, User $coach, User ...$participants): void
    {
        $session->mentors()->syncWithoutDetaching([$coach->id]);
        $this->join($session, ...$participants);
        foreach ($participants as $participant) {
            MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * Add the participants to the session.
     */
    private function join(TrainingSession $session, User ...$participants): void
    {
        foreach ($participants as $participant) {
            $session->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        }
    }

    /**
     * Build a program with Weeks 2–4 holding the given number of activities each and a session of it, have the given
     * number of participants (all mentored by a new coach in that session) submit and get a published score on every
     * activity, and count the queries of the coach's participant summary export after a warm-up request.
     */
    private function participantSummaryQueryCount(int $rows): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->create();
        $participants = User::factory()->count($rows)->create();
        $session = TrainingSession::factory()->forWeek(TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1]))->create();
        $this->mentor($session, $coach, ...$participants->all());

        foreach ([2, 3, 4] as $weekNumber) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);

            foreach (range(1, $rows) as $position) {
                $activity = Activity::factory()->for($week, 'week')->create(['position' => $position]);

                foreach ($participants as $participant) {
                    $this->createScoredSubmission($participant, $activity, $coach, 80);
                }
            }
        }

        $url = route('coach.reports.participant-summary');
        $this->actingAs($coach)->get($url)->assertOk()->streamedContent();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $csv = $this->actingAs($coach)->get($url)->assertOk()->streamedContent();
        DB::disableQueryLog();
        $this->assertCount($rows + 1, explode("\n", trim($csv)));

        return count(DB::getQueryLog());
    }

    private function createScoredSubmission(User $participant, Activity $activity, User $scorer, int $totalScore, int $attemptNumber = 1, ActivityScoreStatus $status = ActivityScoreStatus::Published, string $submissionStatus = 'submitted', ?TrainingSession $session = null): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create([
            'status' => $submissionStatus,
            'attempt_number' => $attemptNumber,
            ...($session ? ['training_session_id' => $session->id] : []),
        ]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $version->activityScore()->create(['scorer_id' => $scorer->id, 'status' => $status, 'total_score' => $totalScore, 'performance_band' => 'Proficient']);
    }
}
