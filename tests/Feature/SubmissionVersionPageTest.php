<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivityScoreDimension;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubmissionVersionPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summary_shows_the_version_status_and_responses(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'submitted', [
            'field_values' => ['finding' => 'Login fails on retry', 'checks' => ['source' => 'ok']],
            'participant_note' => 'Checked on staging only.',
        ]);
        $version->interactions()->create(['sequence' => 1, 'prompt' => 'Generate cases', 'output' => 'Case list']);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['Status</dt>', '>Submitted</span>', 'Submission version</dt>', '>1</dd>', 'Participant note', 'Checked on staging only.'], false)
            ->assertSeeInOrder(['Activity responses', 'Finding', 'Login fails on retry', 'Checks', '&quot;source&quot;: &quot;ok&quot;', 'Prompt / output history', 'Prompt 1', 'Generate cases', 'AI Output 1', 'Case list'], false)
            ->assertDontSee('submission-main-action', false);
    }

    public function test_evidence_rows_distinguish_links_and_text_evidence(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'submitted');
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Recording', 'evidence_type' => 'link', 'url' => 'https://example.com/recording', 'content' => null]);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Notes', 'evidence_type' => 'text', 'content' => 'Observed a 500 response.']);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['Recording', 'Type: Link', 'href="https://example.com/recording" target="_blank" rel="noopener noreferrer">Open evidence link</a>', 'Notes', 'Type: Text', 'Text evidence', 'Observed a 500 response.'], false);
    }

    public function test_published_score_lists_the_scored_dimensions_in_order_with_the_review_decisions(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'approved');
        SubmissionReview::factory()->create(['submission_version_id' => $version->id, 'decision' => 'needs_revision', 'comments' => 'Add the boundary case.']);
        SubmissionReview::factory()->create(['submission_version_id' => $version->id, 'decision' => 'approved', 'comments' => null]);
        $score = ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => User::factory()->create(['role' => 'coach'])->id, 'status' => 'published', 'total_score' => 72, 'performance_band' => 'Proficient', 'overall_feedback' => 'Solid validation work.']);
        ActivityScoreDimension::create(['activity_score_id' => $score->id, 'dimension' => 'D3', 'points' => 15, 'evidence_reasoning' => 'Good prompt iteration.']);
        ActivityScoreDimension::create(['activity_score_id' => $score->id, 'dimension' => 'D1', 'points' => 25, 'evidence_reasoning' => '']);

        $html = $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))->assertOk()->getContent();

        $this->assertStringContainsString('<strong>Review status:</strong> Finalized', $html);
        $this->assertSame(2, substr_count($html, 'data-review-row'));
        $this->assertSame(1, preg_match('/border-amber-500[^>]*>Needs revision<\/span>.*Add the boundary case\..*border-emerald-400[^>]*>Approved<\/span>/s', $html));
        $this->assertStringContainsString('<strong>Final score:</strong> 72 / 100 · Proficient', $html);
        $this->assertSame(1, preg_match('/<strong>D1:<\/strong> 25 points.*<strong>D3:<\/strong> 15 points.*Good prompt iteration\..*Overall Feedback.*Solid validation work\./s', $html));
        $this->assertStringNotContainsString('<strong>D2:</strong>', $html);
    }

    public function test_published_dimension_scores_follow_the_activity_rubric_order_then_dimensions_it_no_longer_has(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $activity->update(['configuration' => ['scoring_dimensions' => [
            ['key' => 'D2', 'label' => 'Code quality', 'max' => 60],
            ['key' => 'D1', 'label' => 'Problem solving', 'max' => 40],
        ]]]);
        $version = $this->submittedVersion($participant, $activity, 'approved');
        $score = ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => User::factory()->create(['role' => 'coach'])->id, 'status' => 'published', 'total_score' => 70, 'performance_band' => 'Developing']);
        foreach ([['D5', 5], ['D1', 30], ['D2', 35]] as [$dimension, $points]) {
            ActivityScoreDimension::create(['activity_score_id' => $score->id, 'dimension' => $dimension, 'points' => $points, 'evidence_reasoning' => '']);
        }

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['<strong>D2:</strong> 35 points', '<strong>D1:</strong> 30 points', '<strong>D5:</strong> 5 points'], false);
    }

    #[DataProvider('unscoredReviewStates')]
    public function test_unscored_review_explains_what_happens_next(string $submissionStatus, string $message): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'submitted', submissionStatus: $submissionStatus);
        SubmissionReview::factory()->create(['submission_version_id' => $version->id, 'decision' => 'needs_revision']);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSee('<strong>Review status:</strong> Awaiting Review', false)
            ->assertSee($message)
            ->assertDontSee('Final score:');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unscoredReviewStates(): array
    {
        return [
            'needs revision' => ['needs_revision', 'Needs revision. See the mentor feedback above for the next steps.'],
            'submitted' => ['submitted', 'Awaiting Review. Finalized scores and feedback will appear here after review.'],
        ];
    }

    #[DataProvider('weekFourActivityPositions')]
    public function test_week_four_submissions_show_the_standard_activity_responses(int $position): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture(4, $position);
        $version = $this->submittedVersion($participant, $activity, 'submitted', ['field_values' => ['asset_name' => 'Regression prompt']]);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['Activity responses', 'Asset Name', 'Regression prompt'])
            ->assertDontSee('Week 4 snapshot');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function weekFourActivityPositions(): array
    {
        return [
            'activity 1' => [1],
            'activity 2' => [2],
            'activity 3' => [3],
        ];
    }

    public function test_submission_history_is_hidden_for_a_single_version_and_never_lists_drafts(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'submitted');

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertDontSee('Submission history');

        SubmissionVersion::factory()->for($version->submission, 'submission')->create(['version_number' => 2, 'status' => 'draft']);

        $html = $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))->assertOk()->getContent();

        $this->assertStringContainsString('Submission history', $html);
        $this->assertSame(1, substr_count($html, 'data-version-row'));
        $this->assertStringNotContainsString('Version 2', $html);
    }

    public function test_a_rejected_review_shows_its_decision_label_with_the_danger_tone(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'submitted', submissionStatus: 'failed');
        SubmissionReview::factory()->create(['submission_version_id' => $version->id, 'decision' => 'rejected', 'comments' => 'The fix was not validated.']);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['data-review-row', 'border-danger text-rose-200">Rejected</span>', 'The fix was not validated.'], false);
    }

    #[DataProvider('versionStatusBadges')]
    public function test_summary_badge_shows_the_version_status_with_its_tone(string $status, string $badge): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, $status);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['Status</dt>', $badge, 'Submission version</dt>'], false);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function versionStatusBadges(): array
    {
        return [
            'submitted' => ['submitted', 'border-accent text-cyan-200">Submitted</span>'],
            'legacy completed' => ['completed', 'border-emerald-400 text-emerald-200">Completed</span>'],
        ];
    }

    public function test_a_draft_version_is_not_found(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $version = $this->submittedVersion($participant, $activity, 'submitted');
        $version->update(['status' => 'draft']);

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))->assertNotFound();
    }

    public function test_submission_page_query_count_does_not_grow_with_evidence_reviews_interactions_and_versions(): void
    {
        $this->assertSame($this->submissionPageQueryCount(1), $this->submissionPageQueryCount(3));
    }

    /**
     * Build a submitted version with the given number of evidence items, reviews, prompt / output pairs, scored
     * dimensions and earlier versions, and count the queries of a submission page request after a warm-up request.
     */
    private function submissionPageQueryCount(int $rows): int
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'approved']);
        foreach (range(1, $rows) as $number) {
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $number, 'status' => 'submitted', 'submitted_at' => now()]);
        }
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $rows + 1, 'status' => 'approved', 'submitted_at' => now()]);
        $score = ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => User::factory()->create(['role' => 'coach'])->id, 'status' => 'published', 'total_score' => 80, 'performance_band' => 'Proficient']);

        foreach (range(1, $rows) as $number) {
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->create();
            SubmissionReview::factory()->create(['submission_version_id' => $version->id]);
            $version->interactions()->create(['sequence' => $number, 'prompt' => 'Prompt '.$number, 'output' => 'Output '.$number]);
            ActivityScoreDimension::create(['activity_score_id' => $score->id, 'dimension' => 'D'.$number, 'points' => 5, 'evidence_reasoning' => 'Reasoning '.$number]);
        }

        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week, $activity, $version]))->assertOk()->assertSee('AI Output '.$rows)->assertSee('Version '.$rows);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A non-draft version of the participant's submission for the activity.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submittedVersion(User $participant, Activity $activity, string $status, array $attributes = [], string $submissionStatus = 'submitted'): SubmissionVersion
    {
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $submissionStatus]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status, 'submitted_at' => now(), ...$attributes]);
    }

    /**
     * A manual participant, an activity in the given week and a session of its program that the participant is in.
     *
     * @return array{User, TrainingWeek, Activity, TrainingSession}
     */
    private function activityFixture(int $weekNumber = 2, int $position = 2): array
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => $weekNumber, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => $position]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();

        return [$participant, $week, $activity, $session];
    }
}
