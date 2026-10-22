<?php

namespace Tests\Feature;

use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftFieldLimitTest extends TestCase
{
    use RefreshDatabase;

    private function activityScene(): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week3 = $program->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'manual']);
        GateEvaluation::create([
            'training_gate_id' => $week3->program->weeks()->where('week_number', 2)->first()->gates()->first()->id,
            'user_id' => $participant->id,
            'status' => 'passed',
            'evaluated_at' => now(),
        ]);
        $activity = $week3->activities->firstWhere('title', 'Script from Description');

        return [$week3, $activity, $participant];
    }

    public function test_each_field_type_enforces_its_character_limit_with_friendly_message(): void
    {
        [$week3, $activity, $participant] = $this->activityScene();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week3, $activity]), ['field_values' => ['exact_ai_output' => str_repeat('a', 50001)]])
            ->assertSessionHasErrors(['field_values.exact_ai_output' => 'Exact AI Output must be 50,000 characters or fewer.']);

        $this->actingAs($participant)->post(route('training.submission.draft', [$week3, $activity]), ['field_values' => ['ai_tool' => str_repeat('a', 1001)]])
            ->assertSessionHasErrors(['field_values.ai_tool' => 'AI Tool must be 1,000 characters or fewer.']);

        $this->actingAs($participant)->post(route('training.submission.draft', [$week3, $activity]), ['field_values' => ['repository_thread_link' => str_repeat('a', 2049)]])
            ->assertSessionHasErrors(['field_values.repository_thread_link' => 'Repository / Thread / Drive Link must be 2,048 characters or fewer.']);
    }

    public function test_fields_at_the_exact_character_limit_are_accepted(): void
    {
        [$week3, $activity, $participant] = $this->activityScene();

        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week3, $activity]), ['field_values' => [
            'exact_prompt' => str_repeat('a', 50000), 'exact_ai_output' => str_repeat('a', 50000),
            'ai_tool' => str_repeat('a', 1000),
            'repository_thread_link' => 'https://example.com/'.str_repeat('a', 2028),
        ]])->assertRedirect();
    }
}
