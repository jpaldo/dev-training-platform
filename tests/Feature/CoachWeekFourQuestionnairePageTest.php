<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CoachWeekFourQuestionnairePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_page_lists_the_official_questions_in_order_with_their_reference_options(): void
    {
        config(['training.week_four_interview_questions' => [
            1 => ['key' => 'question_1_usage', 'title' => 'Usage <Frequency>', 'prompt' => 'How often do you use AI?', 'options' => ['Rarely', 'Daily'], 'explanation_key' => 'question_1_usage_explanation'],
            2 => ['key' => 'question_2_tools', 'title' => 'Tools', 'prompt' => 'Which tools do you use?', 'options' => [], 'explanation_key' => null],
        ]]);
        $coach = User::factory()->create(['role' => 'coach']);

        $response = $this->actingAs($coach)->get(route('coach.week-four.questionnaire'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH / MENTOR REFERENCE',
                '>Official AI Proficiency Interview Questionnaire</h1>',
                'It is not shown in participant mock-interview pages.',
                'aria-label="Official interview questions"',
                '>Q1 · Usage &lt;Frequency&gt;</h2>', 'How often do you use AI?', '>Reference options: Rarely · Daily</p>',
                '>Q2 · Tools</h2>', 'Which tools do you use?',
            ], false);

        $this->assertSame(1, substr_count($response->getContent(), 'Reference options:'));
    }
}
