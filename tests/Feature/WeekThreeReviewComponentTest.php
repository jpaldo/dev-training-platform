<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Services\SubmissionAnswerPresenter;
use Illuminate\Testing\TestView;
use Tests\TestCase;

class WeekThreeReviewComponentTest extends TestCase
{
    public function test_root_cause_analysis_lists_the_severity_summary_and_each_finding_with_its_fields(): void
    {
        $view = $this->renderReview(1, ['findings' => [
            ['title' => 'Login fails', 'severity' => 'High', 'description' => 'Valid users cannot sign in', 'human_validation' => 'Confirmed'],
            ['title' => '', 'severity' => 'High'],
        ]]);

        $view->assertSeeInOrder([
            'aria-label="Week 3 participant submission"',
            '>AI-Assisted Root Cause Analysis</h2>',
            '>2 Findings</span>', '>2 High</span>',
            '<details', 'open', 'Finding #1 — Login fails', '>High</span>', '>Confirmed</span>', '</summary>',
            '<dt', '>Severity</dt>', 'High',
            '>Description</dt>', 'Valid users cannot sign in',
            '>Human Validation</dt>', 'Confirmed',
            'Finding #2 — Not provided',
        ], false);
        $this->assertLegacyMarkupIsGone($view);
    }

    public function test_testing_groups_the_participants_path_tests_under_each_finding(): void
    {
        $view = $this->renderReview(2, ['findings' => [
            ['title' => 'Login fails', 'tests' => [
                ['path' => 'manual', 'name' => 'Login regression', 'steps' => 'Submit valid credentials', 'execution_result' => 'Reproduced'],
                ['path' => 'automation', 'name' => 'Automated login check'],
                ['path' => 'manual', 'name' => ''],
            ]],
            ['title' => 'Checkout rounds down', 'tests' => []],
        ]]);

        $view->assertSeeInOrder([
            '>AI-Assisted Testing</h2>', 'Tests are grouped with the finding they were created to investigate.',
            'Finding #1 — Login fails',
            'Login regression', '>Manual</span>', '</h3>', '>Steps</dt>', 'Submit valid credentials', '>Execution Result</dt>', 'Reproduced',
            'Test', '>Manual</span>', '</h3>',
            'Finding #2 — Checkout rounds down', 'No submitted tests for this finding.',
        ], false);
        $view->assertDontSee('Automated login check');
        $this->assertLegacyMarkupIsGone($view);
    }

    public function test_fix_validation_shows_each_tests_validation_and_the_regression_summary(): void
    {
        $view = $this->renderReview(3, [
            'findings' => [['title' => 'Login fails', 'tests' => [
                ['path' => 'manual', 'name' => 'Login regression', 'fix_validation' => ['status' => 'Fixed', 'buggy_result' => 'Failed before fix']],
                ['path' => 'manual', 'name' => 'Logout regression'],
            ]]],
            'regression_areas' => 'Authentication',
            'final_conclusion' => 'Ready to release',
        ]);

        $view->assertSeeInOrder([
            '>Fix &amp; Regression Validation</h2>', 'Each validation is shown with its original finding and test context.',
            'Finding #1 — Login fails',
            'Login regression', '>Validation Status</dt>', 'Fixed', '>Original Issue / Buggy Result</dt>', 'Failed before fix',
            'Logout regression', 'No fix validation was captured for this test.',
            '>Regression Summary</h3>', '>Regression Areas</dt>', 'Authentication', '>Conclusion</dt>', 'Ready to release',
        ], false);
        $this->assertLegacyMarkupIsGone($view);
    }

    public function test_an_unreadable_snapshot_renders_nothing(): void
    {
        $this->assertSame('', trim((string) $this->renderReview(1, 'not a snapshot')));
    }

    private function renderReview(int $activityPosition, mixed $snapshot): TestView
    {
        $review = app(SubmissionAnswerPresenter::class)->weekThreeReview($snapshot, $activityPosition, TrainingPath::Manual);

        return $this->view('components.week-three-review', ['review' => $review]);
    }

    private function assertLegacyMarkupIsGone(TestView $view): void
    {
        $view->assertDontSee('<style', false)
            ->assertDontSee('week-three-', false)
            ->assertDontSee('class="muted"', false);
    }
}
