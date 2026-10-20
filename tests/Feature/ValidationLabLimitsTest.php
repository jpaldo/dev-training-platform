<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\FieldLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationLabLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_artifact_intake_rejects_prompt_over_fifty_thousand_characters(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Over-limit prompt',
            'prompt' => str_repeat('p', FieldLimits::EXACT_PROMPT_OUTPUT + 1),
            'artifact_content' => 'Valid artifact content.',
        ])->assertSessionHasErrors('prompt');
    }

    public function test_artifact_intake_rejects_artifact_content_over_fifty_thousand_characters(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Over-limit artifact',
            'prompt' => 'Paste the exact prompt.',
            'artifact_content' => str_repeat('a', FieldLimits::EXACT_PROMPT_OUTPUT + 1),
        ])->assertSessionHasErrors('artifact_content');
    }

    public function test_artifact_intake_accepts_fields_at_fifty_thousand_characters(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'At-limit artifact',
            'prompt' => str_repeat('p', FieldLimits::EXACT_PROMPT_OUTPUT),
            'artifact_content' => str_repeat('a', FieldLimits::EXACT_PROMPT_OUTPUT),
        ])->assertOk();
    }

    public function test_artifact_intake_checks_artifact_fields_only_after_base_fields_pass(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'feature_area' => 'User Login / OTP Verification',
            'artifact_content' => 'Valid artifact content.',
        ])->assertSessionHasErrors('trainee_name')
            ->assertSessionDoesntHaveErrors(['artifact_type', 'ai_tool', 'artifact_name']);
    }

    public function test_lab_activity_page_renders_char_limit_and_counter_for_prompt_and_artifact(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $response = $this->actingAs($participant)->get(route('lab.activity'))->assertOk();

        $content = $response->getContent();
        $this->assertSame(2, substr_count($content, 'maxlength="'.FieldLimits::EXACT_PROMPT_OUTPUT.'"'));
        $this->assertSame(2, substr_count($content, 'data-char-count'));
    }
}
