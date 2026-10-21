<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\FieldLimits;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ValidationLabPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_sees_the_lab_activity_form_with_its_fields_defaults_and_single_submit(): void
    {
        $response = $this->get(route('lab.activity'))
            ->assertOk()
            ->assertSeeInOrder([
                'AI-OUTPUT VALIDATION',
                '>Choose what you want to validate</h1>',
                'Select a feature or area and the AI-generated QA artifact you want to inspect.',
                'A controlled QA application where Manual and Automation QA participants practice validating AI-generated output',
                'method="post" action="'.route('start').'"',
                'name="_token"',
                '<label for="trainee_name"',
                'id="trainee_name" name="trainee_name" value="" required',
                '<label for="feature_area"',
                'id="feature_area" name="feature_area" data-other-select="feature_area" required',
                '<option value="User Login / OTP Verification" selected>',
                '<option value="Other" >Other</option>',
                'id="other_feature_area_wrap" hidden',
                'id="other_feature_area" name="other_feature_area" value="" maxlength="160" placeholder="Enter the feature or area…"',
                '<label for="artifact_type"',
                'id="artifact_type" name="artifact_type" data-other-select="artifact_type" required',
                '<option value="Test Case" selected>',
                'Select the type of AI-generated QA artifact you are validating or using for this activity.',
                'id="other_artifact_type_wrap" hidden',
                'id="other_artifact_type" name="other_artifact_type" value="" maxlength="120" placeholder="Enter the artifact type…"',
                '<label for="ai_tool"',
                'id="ai_tool" name="ai_tool" value="Claude" required',
                '<label for="prompt"',
                'id="prompt" name="prompt"',
                'Preserve the complete prompt; do not summarize it.',
                '<label for="artifact_name"',
                'id="artifact_name" name="artifact_name" maxlength="160" value="" required placeholder="e.g. Login validation test case"',
                '<label for="artifact_content"',
                'id="artifact_content" name="artifact_content"',
                'Paste the actual output. Do not summarize or invent the AI response.',
                'type="submit"',
                '>Start Activity 1 · Continue to Artifact Preview →</button>',
                '</form>',
            ], false)
            ->assertDontSee('role="status"', false);

        $content = $response->getContent();
        $this->assertStringStartsWith('<!doctype html>', ltrim($content));
        $this->assertSame(1, substr_count($content, 'type="submit"'));
        $this->assertSame(2, substr_count($content, 'maxlength="'.FieldLimits::EXACT_PROMPT_OUTPUT.'"'));
        $this->assertStringNotContainsString('<script>', $content);
    }

    public function test_signed_in_participant_gets_their_name_prefilled_and_the_lab_status_notice(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'name' => 'Lab Participant']);

        $this->actingAs($participant)
            ->withSession(['status' => 'Complete and submit a QA Validation Lab record before starting the Validation Checklist.'])
            ->get(route('lab.activity'))
            ->assertOk()
            ->assertSeeInOrder([
                'role="status"',
                'Complete and submit a QA Validation Lab record before starting the Validation Checklist.',
                '>Choose what you want to validate</h1>',
                'id="trainee_name" name="trainee_name" value="Lab Participant" required',
            ], false);
    }

    public function test_rejected_start_returns_to_the_form_with_the_typed_other_values_kept(): void
    {
        $this->from(route('lab.activity'))
            ->post(route('start'), [
                'trainee_name' => '',
                'feature_area' => 'Other',
                'other_feature_area' => 'Payments',
                'artifact_type' => 'Other',
                'other_artifact_type' => 'Load test',
                'ai_tool' => 'Copilot',
                'artifact_content' => 'Some output.',
            ])
            ->assertRedirect(route('lab.activity'));

        $this->get(route('lab.activity'))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="Other" selected>Other</option>',
                'id="other_feature_area" name="other_feature_area" value="Payments"',
                '<option value="Other" selected>Other</option>',
                'id="other_artifact_type" name="other_artifact_type" value="Load test"',
                'id="ai_tool" name="ai_tool" value="Copilot"',
            ], false);
    }

    public function test_mock_login_keeps_its_unnamed_prefilled_demo_fields_and_posts_to_send_otp(): void
    {
        $response = $this->get(route('lab.login'))
            ->assertOk()
            ->assertSeeInOrder([
                'CONTROLLED QA APPLICATION',
                '>Secure Login</h1>',
                'Demo account for testing.',
                'method="post" action="'.route('lab.send-otp').'"',
                '>Email</label>',
                '<input type="email" value="tester@example.com" required',
                '>Password</label>',
                '<input type="password" value="Password123!" required',
                'type="submit"',
                '>Sign in →</button>',
                '</form>',
            ], false);

        $form = $this->formMarkup($response->getContent());
        $this->assertSame(1, substr_count($form, 'name='), 'Only the CSRF token is named; the demo fields post nothing.');
        $this->assertStringNotContainsString(' id=', $form);
        $this->assertStringNotContainsString(' for=', $form);
    }

    public function test_sign_in_leads_to_the_otp_page_with_the_lab_code_hint_and_resend_alert(): void
    {
        $this->post(route('lab.send-otp'))->assertRedirect(route('lab.otp'));

        $response = $this->get(route('lab.otp'))
            ->assertOk()
            ->assertSeeInOrder([
                'CONTROLLED QA APPLICATION',
                '>Enter OTP</h1>',
                'A 6-digit OTP was sent to the registered mobile number (simulated).',
                'use <strong class="text-slate-100">248163</strong> to verify successfully.',
                'The validity window is <strong class="text-slate-100">2 minutes</strong>, not 5 minutes.',
                'method="post" action="'.route('lab.verify-otp').'"',
                '>6-digit OTP</label>',
                '<input name="otp" inputmode="numeric" maxlength="6" required',
                'type="submit"',
                '>Verify OTP</button>',
                '</form>',
                '<button type="button"',
                'onclick="alert(\'A new OTP was sent. The validity window remains 2 minutes.\')"',
                '>Resend OTP</button>',
            ], false);

        $form = $this->formMarkup($response->getContent());
        $this->assertStringNotContainsString(' id=', $form);
        $this->assertStringNotContainsString(' for=', $form);
        $this->assertStringNotContainsString('Invalid OTP', $response->getContent());
    }

    public function test_wrong_otp_returns_to_the_otp_page_with_the_blocking_message(): void
    {
        $this->from(route('lab.otp'))
            ->post(route('lab.verify-otp'), ['otp' => '123456'])
            ->assertRedirect(route('lab.otp'));

        $this->get(route('lab.otp'))
            ->assertOk()
            ->assertSeeInOrder([
                'A 6-digit OTP was sent to the registered mobile number (simulated).',
                'Invalid OTP. After 3 failed attempts, the account is temporarily blocked.',
                'method="post" action="'.route('lab.verify-otp').'"',
            ], false);
    }

    public function test_correct_otp_shows_the_authenticated_page_with_the_checklist_link(): void
    {
        $this->post(route('lab.verify-otp'), ['otp' => '248163'])
            ->assertOk()
            ->assertSeeInOrder([
                '✓',
                '>Authenticated</h1>',
                'The OTP was accepted. Continue your Activity 1 validation using the evidence you observed.',
                'href="'.route('checklist').'"',
                '>Return to Checklist →</a>',
            ], false);
    }

    public function test_authenticated_page_is_reachable_directly_without_an_otp(): void
    {
        $this->get(route('lab.success'))
            ->assertOk()
            ->assertSee('>Authenticated</h1>', false);
    }

    /**
     * The page's first form, from its opening tag to its closing tag.
     */
    private function formMarkup(string $content): string
    {
        $start = strpos($content, '<form');
        $this->assertNotFalse($start);

        return substr($content, $start, strpos($content, '</form>', $start) - $start);
    }
}
