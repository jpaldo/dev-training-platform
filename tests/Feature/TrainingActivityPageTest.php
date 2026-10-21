<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrainingActivityPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_activity_with_a_lab_shows_all_four_tabs(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(['lab_route' => 'lab.activity']);

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertOk()
            ->assertSee('role="tablist" aria-label="Activity sections"', false)
            ->assertSeeInOrder([
                'id="activity-tab-instructions"',
                'id="activity-tab-requirements"',
                'id="activity-tab-validation-lab"',
                'id="activity-tab-submission"',
            ], false)
            ->assertSee('id="activity-panel-validation-lab"', false)
            ->assertSee('Open QA Lab · Complete Validation Lab →');
    }

    public function test_activity_without_a_lab_hides_the_validation_lab_tab(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'id="activity-tab-instructions"',
                'id="activity-tab-requirements"',
                'id="activity-tab-submission"',
            ], false)
            ->assertDontSee('activity-tab-validation-lab', false)
            ->assertDontSee('activity-panel-validation-lab', false);
    }

    #[DataProvider('submissionStates')]
    public function test_single_main_action_matches_the_submission_state(?string $status, string $actionLabel, bool $isRevisionForm, string $selectedTab): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        if ($status !== null) {
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status === 'draft' ? 'draft' : 'submitted']);
        }

        $html = $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div id="activity-main-action"[^>]*>(.*?)<\/div>/s', $html, $mainAction));
        $this->assertStringContainsString($actionLabel, $mainAction[1]);
        $this->assertStringContainsString($isRevisionForm
            ? 'method="POST" action="'.route('training.submission.revise', [$week, $activity]).'"'
            : 'href="'.route('training.submission.edit', [$week, $activity]).'"', $mainAction[1]);
        $this->assertSame(1, substr_count($html, $actionLabel));
        $this->assertStringContainsString('id="activity-tab-'.$selectedTab.'" aria-controls="activity-panel-'.$selectedTab.'" aria-selected="true"', $html);
    }

    /**
     * @return array<string, array{?string, string, bool, string}>
     */
    public static function submissionStates(): array
    {
        return [
            'not started' => [null, 'Start submission →', false, 'instructions'],
            'draft' => ['draft', 'Continue submission →', false, 'submission'],
            'needs revision' => ['needs_revision', 'Revise submission →', true, 'submission'],
        ];
    }

    public function test_activity_page_query_count_does_not_grow_with_requirements_and_attempts(): void
    {
        $this->assertSame($this->activityPageQueryCount(1), $this->activityPageQueryCount(3));
    }

    /**
     * Build a Lab activity with the given number of requirements and failed attempts (each with evidence, a coach
     * review and a review request), and count the queries of an activity page request after a warm-up request.
     */
    private function activityPageQueryCount(int $rows): int
    {
        [$participant, $week, $activity] = $this->activityFixture(['lab_route' => 'lab.activity']);

        foreach (range(1, $rows) as $position) {
            $requirement = ActivityRequirement::factory()->for($activity)->create(['position' => $position]);
            $evidenceRequirement = EvidenceRequirement::factory()->for($requirement)->create();
            $attempt = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['attempt_number' => $position, 'status' => 'failed', 'submitted_at' => now()]);
            $version = SubmissionVersion::factory()->for($attempt, 'submission')->create(['status' => 'failed', 'submitted_at' => now()]);
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
            SubmissionReview::factory()->create(['submission_version_id' => $version->id, 'decision' => 'rejected']);
            ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $attempt->id, 'status' => 'declined', 'requested_at' => now()]);
        }

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))->assertOk()->assertSee('Attempt '.$rows);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A checked-in manual participant and a Week 2 activity that is not the Validation Checklist.
     *
     * @param  array<string, mixed>  $configuration
     * @return array{User, TrainingWeek, Activity}
     */
    private function activityFixture(array $configuration = []): array
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 2, 'configuration' => $configuration]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $week, $activity];
    }
}
