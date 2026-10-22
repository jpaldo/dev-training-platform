<?php

namespace Tests\Feature;

use App\Enums\ActivityScoreStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
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
        $coach = User::factory()->create(['role' => 'coach']);

        $singleRowQueryCount = $this->participantSummaryQueryCount($coach, 1);

        Program::query()->update(['is_active' => false]);

        $threeRowQueryCount = $this->participantSummaryQueryCount($coach, 3);

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
        $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'training_path' => 'manual']);
        $ben = User::factory()->create(['name' => 'Ben', 'email' => 'ben@example.com', 'training_path' => 'automation']);
        $ada->assignedCoaches()->attach($coach->id);

        $this->createScoredSubmission($ada, $firstActivity, $coach, 80);
        $this->createScoredSubmission($ada, $secondActivity, $coach, 90);
        $this->createScoredSubmission($ben, $firstActivity, $coach, 60);
        $this->createScoredSubmission($ben, $weekThreeActivity, $coach, 20);
        $this->travel(1)->hour();
        $this->createScoredSubmission($ben, $weekThreeActivity, $coach, 70, attemptNumber: 2);

        $csv = $this->actingAs($coach)->get(route('coach.reports.participant-summary'))->assertOk()->streamedContent();
        $rows = collect(explode("\n", trim($csv)))->skip(1)->map(fn (string $line): array => str_getcsv($line))->keyBy(0);

        $this->assertCount(2, $rows);
        $this->assertSame(['Ada', 'ada@example.com', 'manual', 'Casey Coach', '2', '2', '0', '85', '', '', '85', 'Proficient', '2026-10-01 09:00:00'], $rows['Ada']);
        $this->assertSame(['Ben', 'ben@example.com', 'automation', '', '2', '2', '0', '60', '70', '', '65', 'Developing', '2026-10-01 10:00:00'], $rows['Ben']);
    }

    public function test_participant_summary_counts_unscored_activities_only_among_completed_ones(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        [$scoredActivity, $revisionActivity, $unscoredActivity] = Activity::factory()->for($week, 'week')->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create()->all();
        $participant = User::factory()->create(['name' => 'Ada']);
        $this->createScoredSubmission($participant, $scoredActivity, $coach, 80);
        $this->createScoredSubmission($participant, $revisionActivity, $coach, 60, submissionStatus: 'needs_revision');
        $unscoredSubmission = ActivitySubmission::factory()->for($unscoredActivity)->for($participant, 'participant')->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($unscoredSubmission, 'submission')->create(['status' => 'submitted']);

        $csv = $this->actingAs($coach)->get(route('coach.reports.participant-summary'))->assertOk()->streamedContent();
        $row = str_getcsv(explode("\n", trim($csv))[1]);

        $this->assertSame(['Ada', '2', '2', '1'], [$row[0], $row[4], $row[5], $row[6]]);
    }

    public function test_activity_scores_export_prints_the_scoring_status_of_each_submitted_version(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $published = Activity::factory()->for($week, 'week')->create(['title' => 'Published activity', 'position' => 1]);
        $draft = Activity::factory()->for($week, 'week')->create(['title' => 'Draft activity', 'position' => 2]);
        $unscored = Activity::factory()->for($week, 'week')->create(['title' => 'Unscored activity', 'position' => 3]);

        $this->createScoredSubmission($participant, $published, $coach, 83);
        $this->createScoredSubmission($participant, $draft, $coach, 41, status: ActivityScoreStatus::Draft);
        $unscoredSubmission = ActivitySubmission::factory()->for($unscored)->for($participant, 'participant')->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($unscoredSubmission, 'submission')->create(['status' => 'submitted']);

        $csv = $this->actingAs($coach)->get(route('coach.reports.scores'))->assertOk()->streamedContent();
        $statuses = collect(explode("\n", trim($csv)))->skip(1)->map(fn (string $line): array => str_getcsv($line))->mapWithKeys(fn (array $row): array => [$row[4] => [$row[7], $row[13]]]);

        $this->assertSame(['Published activity' => ['published', '83'], 'Draft activity' => ['draft', '41'], 'Unscored activity' => ['unscored', '']], $statuses->all());
    }

    /**
     * Build an active program with Weeks 2–4 holding the given number of activities each, have the given number of
     * participants submit and get a published score on every activity, and count the queries of a participant
     * summary export after a warm-up request.
     */
    private function participantSummaryQueryCount(User $coach, int $rows): int
    {
        $program = Program::factory()->create();
        $participants = User::factory()->count($rows)->create();

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
        $this->actingAs($coach)->get($url)->assertOk()->streamedContent();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function createScoredSubmission(User $participant, Activity $activity, User $scorer, int $totalScore, int $attemptNumber = 1, ActivityScoreStatus $status = ActivityScoreStatus::Published, string $submissionStatus = 'submitted'): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => $submissionStatus, 'attempt_number' => $attemptNumber]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $version->activityScore()->create(['scorer_id' => $scorer->id, 'status' => $status, 'total_score' => $totalScore, 'performance_band' => 'Proficient']);
    }
}
