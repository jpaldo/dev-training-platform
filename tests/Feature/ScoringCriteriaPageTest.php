<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ScoringCriteriaPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_bands_and_every_dimension_with_its_maximum_and_band_ranges_in_rubric_order(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.criteria'))
            ->assertOk()
            ->assertSeeInOrder([
                'SCORING CRITERIA',
                '>AI Level 3 Accelerator</h1>',
                'Activity Scoring Criteria · Version 1.0',
                '>0–100 performance bands</h2>',
                'Exemplary 90–100 · Proficient 80–89 · Developing 60–79 · Beginning 35–59 · Minimal 1–34 · Not submitted / Not evidenced 0.',
                'D1 · MAX 30', '>Deliverable quality</h3>',
                '<strong>Exemplary:</strong> 27–30', '<strong>Proficient:</strong> 24–26', '<strong>Developing:</strong> 18–23', '<strong>Beginning:</strong> 11–17', '<strong>Minimal:</strong> 1–10',
                'Not evidenced: 0',
                'D2 · MAX 25', '>Validation &amp; verification</h3>', '<strong>Exemplary:</strong> 23–25', '<strong>Minimal:</strong> 1–8',
                'D3 · MAX 20', '>AI-augmented workflow</h3>', '<strong>Exemplary:</strong> 18–20', '<strong>Minimal:</strong> 1–6',
                'D4 · MAX 15', '>Judgment &amp; iteration</h3>', '<strong>Exemplary:</strong> 14–15', '<strong>Minimal:</strong> 1–5',
                'D5 · MAX 10', '>Evidence &amp; reasoning</h3>', '<strong>Exemplary:</strong> 9–10', '<strong>Proficient:</strong> 8', '<strong>Minimal:</strong> 1–3',
                'Not evidenced: 0',
                '>Scoring process</h2>',
                'Totals from 76–84 or differences greater than 15 points from the running average require a second reader.',
                '>Bias watch</h2>',
                '>QA interpretation</h2>',
            ], false);
    }

    public function test_participant_cannot_open_the_scoring_criteria(): void
    {
        $this->actingAs(User::factory()->create())->get(route('coach.scoring.criteria'))->assertForbidden();
    }
}
