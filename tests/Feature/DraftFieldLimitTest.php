<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftFieldLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An open first week with one activity that has a prompt and an AI output textarea, a text field and a URL field.
     *
     * @return array{0: TrainingWeek, 1: Activity, 2: User}
     */
    private function activityScene(): array
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        foreach ([
            ['key' => 'exact_prompt', 'label' => 'Exact Prompt', 'type' => 'textarea'],
            ['key' => 'exact_ai_output', 'label' => 'Exact AI Output', 'type' => 'textarea'],
            ['key' => 'ai_tool', 'label' => 'AI Tool', 'type' => 'text'],
            ['key' => 'repository_thread_link', 'label' => 'Repository / Thread / Drive Link', 'type' => 'url'],
        ] as $field) {
            ActivityRequirement::factory()->for($activity)->create([
                'requirement_type' => 'submission_field',
                'configuration' => [...$field, 'required' => false],
            ]);
        }
        $participant = User::factory()->create();

        return [$week, $activity, $participant];
    }

    public function test_each_field_type_enforces_its_character_limit_with_friendly_message(): void
    {
        [$week, $activity, $participant] = $this->activityScene();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['exact_ai_output' => str_repeat('a', 50001)]])
            ->assertSessionHasErrors(['field_values.exact_ai_output' => 'Exact AI Output must be 50,000 characters or fewer.']);

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['ai_tool' => str_repeat('a', 1001)]])
            ->assertSessionHasErrors(['field_values.ai_tool' => 'AI Tool must be 1,000 characters or fewer.']);

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['repository_thread_link' => str_repeat('a', 2049)]])
            ->assertSessionHasErrors(['field_values.repository_thread_link' => 'Repository / Thread / Drive Link must be 2,048 characters or fewer.']);
    }

    public function test_fields_at_the_exact_character_limit_are_accepted(): void
    {
        [$week, $activity, $participant] = $this->activityScene();

        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => [
            'exact_prompt' => str_repeat('a', 50000), 'exact_ai_output' => str_repeat('a', 50000),
            'ai_tool' => str_repeat('a', 1000),
            'repository_thread_link' => 'https://example.com/'.str_repeat('a', 2028),
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', 1000), $activity->submissions()->sole()->versions()->sole()->field_values['ai_tool']);
    }
}
