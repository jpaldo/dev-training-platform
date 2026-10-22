<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Services\WeekFourReviewPresenter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestView;
use Tests\TestCase;

class WeekFourReviewComponentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_reusable_asset_lists_each_section_with_its_answered_fields(): void
    {
        $view = $this->renderReview(1, [
            'asset_name' => 'Regression prompt',
            'asset_type' => '',
            'original_task' => 'Checkout smoke test',
        ]);

        $view->assertSeeInOrder([
            '<section', '>Reusable AI Asset</h3>', '<dt', '>Asset</dt>', 'Regression prompt',
            '>Original Use</h3>', '>Task / Scenario</dt>', 'Checkout smoke test',
            '>Second Real-Task Validation</h3>',
            '>Reflection</h3>',
        ], false);
        $view->assertDontSee('>Type</dt>', false);
        $this->assertLegacyMarkupIsGone($view);
    }

    public function test_the_assessment_lists_the_interview_and_tracker_sections(): void
    {
        $view = $this->renderReview(3, ['strict_interview_transcript' => 'Full transcript', 'assessment_level' => 'Level 3']);

        $view->assertSeeInOrder([
            '>Strict-mode Interview</h3>', '>Transcript</dt>', 'Full transcript',
            '>Assessment Tracker</h3>', '>Level</dt>', 'Level 3',
        ], false);
        $this->assertLegacyMarkupIsGone($view);
    }

    public function test_a_peer_practice_interview_shows_the_partner_mentor_and_both_question_directions(): void
    {
        $view = $this->renderReview(2, [
            'partner_snapshot' => ['display_name' => 'Participant B', 'email' => 'b@example.com', 'training_path' => 'automation_manual'],
            'interview_date' => '2026-10-20',
            'practice_interview_snapshot' => [
                'mentor_schedule' => ['mentor_name' => 'Mentor M', 'mentor_email' => 'm@example.com', 'status' => 'scheduled', 'starts_at' => 'Oct 22, 2026 10:00 AM', 'timezone' => 'Asia/Manila', 'duration_minutes' => 30],
                'questions_for_partner' => [1 => 'Describe a flaky test you fixed.'],
                'partner_answers' => [1 => 'I quarantined it first.'],
                'feedback_given' => [1 => ['content' => 'Clear and specific.']],
                'partner_questions' => [1 => 'How do you review AI output?'],
                'answers_to_partner' => [1 => ''],
                'feedback_received' => [],
            ],
        ]);

        $view->assertSeeInOrder([
            '>Interview Partner</h3>', '<strong>Participant B</strong>', 'b@example.com', 'Automation / Manual QA',
            '<strong>Interview Date</strong>', '2026-10-20',
            '>Mentor Mock Interview</h3>', '<strong>Mentor M</strong>', 'm@example.com',
            '<strong>Status:</strong> Scheduled', '<strong>Scheduled:</strong> Oct 22, 2026 10:00 AM (Asia/Manila)', '<strong>Duration:</strong> 30 minutes',
            '>Questions Asked by Participant</h3>', '>Q1</h4>',
            '<strong>Practice Question</strong>', 'Describe a flaky test you fixed.',
            '<strong>Partner Answer</strong>', 'I quarantined it first.',
            '<strong>Participant Feedback</strong>', 'Clear and specific.',
            '>Questions Asked by Partner</h3>', '>Q1</h4>',
            '<strong>Practice Question</strong>', 'How do you review AI output?',
            '<strong>Participant Answer</strong>', 'Not provided',
            '<strong>Partner Feedback</strong>', 'Not provided',
        ], false);
        $view->assertDontSee('Historical Interview Responses');
        $this->assertLegacyMarkupIsGone($view);
    }

    public function test_a_legacy_interview_shows_one_group_of_historical_responses_without_a_mentor_or_path(): void
    {
        $view = $this->renderReview(2, ['partner_name' => 'Legacy Partner', 'question_1_answer' => 'Answer one']);

        $view->assertSeeInOrder([
            '<strong>Legacy Partner</strong>',
            '>Historical Interview Responses</h3>',
            '>Q1</h4>', '<strong>Participant Answer</strong>', 'Answer one', '<strong>Partner Feedback</strong>', 'Not provided',
            '>Q2</h4>', '>Q3</h4>',
        ], false);
        $view->assertDontSee('Mentor Mock Interview')
            ->assertDontSee('Interview Date')
            ->assertDontSee('Practice Question')
            ->assertDontSee(' QA</span>', false);
        $this->assertLegacyMarkupIsGone($view);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function renderReview(int $activityPosition, array $values): TestView
    {
        $week = TrainingWeek::factory()->create(['week_number' => 4, 'position' => 4]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => $activityPosition]);
        $review = app(WeekFourReviewPresenter::class)->present($activity, $values);

        return $this->view('components.week-four-review', ['review' => $review]);
    }

    private function assertLegacyMarkupIsGone(TestView $view): void
    {
        $view->assertDontSee('style=', false)
            ->assertDontSee('class="check"', false)
            ->assertDontSee('class="ai"', false)
            ->assertDontSee('class="muted"', false);
    }
}
