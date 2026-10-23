<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ScoringCriteriaPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_bands_and_each_active_activity_with_its_dimensions_maxima_and_band_ranges_in_rubric_order(): void
    {
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $weekTwo = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $weekOne = TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1]);
        Activity::factory()->for($weekTwo, 'week')->withScoringDimensions([['key' => 'D1', 'label' => 'Tiny rubric', 'max' => 2]])->create(['title' => 'Small task', 'position' => 1]);
        Activity::factory()->for($weekOne, 'week')->create(['title' => 'Write the README', 'position' => 2]);
        Activity::factory()->for($weekOne, 'week')->withScoringDimensions([
            ['key' => 'D1', 'label' => 'Problem solving', 'max' => 50],
            ['key' => 'D2', 'label' => 'Code quality', 'max' => 30],
            ['key' => 'D3', 'label' => 'Testing & verification', 'max' => 10],
            ['key' => 'D4', 'label' => 'Reflection', 'max' => 10],
        ])->create(['title' => 'Build the API', 'position' => 1]);
        Activity::factory()->for($weekOne, 'week')->withScoringDimensions()->create(['title' => 'Retired activity', 'position' => 3, 'is_active' => false]);
        Activity::factory()->withScoringDimensions()->create(['title' => 'Other program activity']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.criteria'))
            ->assertOk()
            ->assertSeeInOrder([
                'SCORING CRITERIA',
                '>L4 Accelerator Training</h1>',
                'Activity Scoring Criteria · Version 1.0',
                '>0–100 performance bands</h2>',
                'Exemplary 90–100 · Proficient 80–89 · Developing 60–79 · Beginning 35–59 · Minimal 1–34 · Not submitted / Not evidenced 0.',
                '>Week 1 · Build the API</h2>',
                'D1 · MAX 50', '>Problem solving</h3>',
                '<strong>Exemplary:</strong> 45–50', '<strong>Proficient:</strong> 40–44', '<strong>Developing:</strong> 30–39', '<strong>Beginning:</strong> 18–29', '<strong>Minimal:</strong> 1–17',
                'Not evidenced: 0',
                'D2 · MAX 30', '>Code quality</h3>', '<strong>Exemplary:</strong> 27–30', '<strong>Minimal:</strong> 1–10',
                'D3 · MAX 10', '>Testing &amp; verification</h3>', '<strong>Exemplary:</strong> 9–10', '<strong>Proficient:</strong> 8', '<strong>Minimal:</strong> 1–3',
                'D4 · MAX 10', '>Reflection</h3>',
                '>Week 1 · Write the README</h2>', 'This activity has no scoring dimensions, so it cannot be scored.',
                '>Week 2 · Small task</h2>', 'D1 · MAX 2', '<strong>Exemplary:</strong> 2', '<strong>Proficient:</strong> —', '<strong>Beginning:</strong> 1', '<strong>Minimal:</strong> —',
                '>Scoring process</h2>',
                'Totals from 76–84 or differences greater than 15 points from the running average require a second reader.',
                '>Bias watch</h2>',
            ], false)
            ->assertDontSee('Retired activity')
            ->assertDontSee('Other program activity');
    }

    public function test_without_an_active_program_the_page_says_there_is_nothing_to_score(): void
    {
        Activity::factory()->for(TrainingWeek::factory()->for(Program::factory()->state(['is_active' => false])), 'week')->withScoringDimensions()->create(['title' => 'Inactive program activity']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.criteria'))
            ->assertOk()
            ->assertSeeInOrder(['>No active program</h1>', 'No activities to score yet.', '>Scoring process</h2>'], false)
            ->assertDontSee('Inactive program activity');
    }

    public function test_participant_cannot_open_the_scoring_criteria(): void
    {
        $this->actingAs(User::factory()->create())->get(route('coach.scoring.criteria'))->assertForbidden();
    }
}
