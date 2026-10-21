<?php

namespace Tests\Feature;

use App\Enums\ValidationSubmissionStatus;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\EvidenceFilePolicy;
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

    public function test_started_artifact_preview_shows_the_artifact_and_links_to_the_checklist(): void
    {
        $response = $this->post(route('start'), [
            'trainee_name' => 'QA Participant',
            'feature_area' => 'Registration',
            'artifact_type' => 'Test Plan',
            'ai_tool' => 'ChatGPT',
            'artifact_name' => 'Registration plan',
            'artifact_content' => 'Plan **content**.',
        ])
            ->assertOk()
            ->assertSeeInOrder([
                'ACTIVITY 1 · STEP 1',
                '>Review your selected artifact</h1>',
                'Trainee: <strong class="text-slate-100">QA Participant</strong>',
                'AI GENERATED · QA VALIDATION · AI-TC-015',
                'Test Plan',
                '>Registration plan</h2>',
                'Feature / Area:</strong> Registration · <strong class="text-slate-100">AI Tool:</strong> ChatGPT',
                'Plan <strong>content</strong>.',
                '<strong>Your job:</strong> Do not assume the AI output is correct. Use the application, requirements, and evidence.',
                'href="'.route('checklist').'"',
                '>Run 6-Check Validation →</a>',
            ], false);

        $this->assertStringNotContainsString('<script>', $response->getContent());
    }

    public function test_checklist_requires_a_started_lab_session(): void
    {
        $this->get(route('checklist'))->assertForbidden();
    }

    public function test_checklist_shows_the_started_artifact_and_the_six_check_form_in_order(): void
    {
        $this->post(route('start'), [
            'trainee_name' => 'QA Participant',
            'feature_area' => 'Registration',
            'artifact_type' => 'Test Plan',
            'ai_tool' => 'ChatGPT',
            'prompt' => 'Write a registration plan.',
            'artifact_name' => 'Registration plan',
            'artifact_content' => 'Plan content.',
        ])->assertOk();

        $response = $this->get(route('checklist'))
            ->assertOk()
            ->assertSeeInOrder([
                'ACTIVITY 1 · SIX-CHECK VALIDATION',
                '>Prove it.</h1>',
                'Artifact ID: <strong class="text-slate-100">AI-TC-015</strong> · Trainee: <strong class="text-slate-100">QA Participant</strong>',
                'method="post" action="'.route('checklist.submit').'" enctype="multipart/form-data" data-markdown-flush-on-submit',
                'name="_token"',
                '<input type="hidden" name="artifact_code" value="AI-TC-015">',
                '<input type="hidden" name="feature_area" value="Registration">',
                '<input type="hidden" name="artifact_type" value="Test Plan">',
                '<input type="hidden" name="ai_tool" value="ChatGPT">',
                '<textarea name="artifact_content" hidden>Plan content.</textarea>',
                '<textarea name="prompt" hidden>Write a registration plan.</textarea>',
                'SELECTED AI ARTIFACT',
                '>Registration plan</h2>',
                'Feature / Area:</strong> Registration · <strong class="text-slate-100">Type:</strong> Test Plan · <strong class="text-slate-100">AI Tool:</strong> ChatGPT',
                '<label for="artifact_name"',
                'id="artifact_name" name="artifact_name" type="text" value="Registration plan" maxlength="160" required placeholder="e.g. OTP validation response — 5-minute validity claim"',
                '>1</span>', '>SOURCE</strong>', 'Where did the AI get this from?', 'name="source_status" required', 'name="source_notes" maxlength="'.FieldLimits::CHECK_NOTES.'" required',
                '>2</span>', '>SCOPE</strong>', 'Does it match the actual feature/build?', 'name="scope_status"', 'name="scope_notes"',
                '>3</span>', '>SPEC</strong>', 'Does it match requirements and acceptance criteria?', 'name="spec_status"', 'name="spec_notes"',
                '>4</span>', '>CROSS-CHECK</strong>', 'Can another source/evidence confirm it?', 'name="cross_check_status"', 'name="cross_check_notes"',
                '>5</span>', '>RUN</strong>', 'Did you execute the important steps yourself?', 'name="run_status"', 'name="run_notes"',
                '>6</span>', '>LOG</strong>', 'Did you record what happened at the time?', 'name="log_status"', 'name="log_notes"',
                '0 / 2,000 characters',
                '>What did the validation catch?</label>',
                'id="finding" name="finding"',
                '>Decision</label>',
                '<select name="decision" required',
                '<option value="">Select</option>',
                '<option value="accept" >Accept</option>',
                '<option value="revise" >Revise</option>',
                '<option value="reject" >Reject</option>',
                '<option value="reprompt" >Re-prompt AI</option>',
                '>Evidence record</label>',
                'id="evidence_notes" name="evidence_notes"',
                '<label for="evidence_files"',
                'id="evidence_files" name="evidence_files[]" type="file" accept="'.app(EvidenceFilePolicy::class)->acceptAttribute().'" multiple',
                'Attach screenshots or files supporting your six-check findings.',
                'type="submit"',
                '>Submit Validation Record</button>',
                '</form>',
            ], false);

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'type="submit"'));
        $this->assertSame(6, substr_count($content, 'data-character-counter'));
        $this->assertStringNotContainsString('<script>', $content);
    }

    public function test_checklist_without_a_started_artifact_shows_the_requested_historical_artifact(): void
    {
        $this->withSession(['trainee_name' => 'QA Participant'])
            ->get(route('checklist', ['artifact' => 'AI-TC-020']))
            ->assertOk()
            ->assertSeeInOrder([
                'Artifact ID: <strong class="text-slate-100">AI-TC-020</strong>',
                '<input type="hidden" name="artifact_code" value="AI-TC-020">',
                '<textarea name="prompt" hidden></textarea>',
                '>Verify OTP expiration</h2>',
                'Historical artifact preview.',
                'value="Verify OTP expiration"',
            ], false);
    }

    public function test_draft_lab_record_shows_its_checks_and_the_submit_lab_record_form(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $draft = ValidationSubmission::create([
            'user_id' => $participant->id,
            'trainee_name' => 'Draft Owner',
            'artifact_code' => 'AI-TC-050',
            'artifact_name' => 'Draft artifact',
            'source_status' => 'pass',
            'source_notes' => "Line one\nLine two",
            'scope_status' => 'issue',
            'status' => ValidationSubmissionStatus::Draft,
        ]);

        $response = $this->actingAs($participant)
            ->withSession(['status' => 'Continue your saved QA Validation Lab record, then submit it to unlock the Validation Checklist.'])
            ->get(route('result', $draft))
            ->assertOk()
            ->assertSeeInOrder([
                'role="status"',
                'Continue your saved QA Validation Lab record, then submit it to unlock the Validation Checklist.',
                'ACTIVITY 1 · RESULT',
                '>Validation recorded.</h1>',
                '<strong>Artifact ID:</strong> AI-TC-050',
                '<strong>Artifact Name:</strong> Draft artifact',
                'Draft Owner',
                'SOURCE', '✓ PASS', '<strong>Result:</strong> PASS', "Line one\nLine two",
                'SCOPE', '⚠ ISSUE FOUND', '<strong>Result:</strong> ISSUE FOUND', 'No additional details recorded.',
                'SPEC', '⚠ ISSUE FOUND',
                'CROSS-CHECK', '⚠ ISSUE FOUND',
                'RUN', '⚠ ISSUE FOUND',
                'LOG', '⚠ ISSUE FOUND',
                '>Finding</h2>',
                'Not provided',
                'Decision: Not provided',
                'Lab record saved as a draft.',
                'Activity 1 remains locked until you explicitly submit this Lab record.',
                'method="post" action="'.route('lab.records.submit', $draft).'"',
                '>Submit Lab Record</button>',
            ], false)
            ->assertDontSee('Validation record submitted for coach review.')
            ->assertDontSee('Uploaded Evidence')
            ->assertDontSee('Proceed to Activity 1 Submission');

        $this->assertSame(6, substr_count($response->getContent(), '>View Details</summary>'));
    }

    public function test_submitted_lab_record_shows_its_decision_and_uploaded_evidence(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $record = ValidationSubmission::create([
            'user_id' => $participant->id,
            'trainee_name' => 'Record Owner',
            'artifact_code' => 'AI-TC-051',
            'artifact_name' => 'Submitted artifact',
            'feature_area' => 'Registration',
            'artifact_type' => 'Test Plan',
            'ai_tool' => 'ChatGPT',
            'finding' => 'The expiry claim is wrong.',
            'decision' => 'reprompt',
            'evidence_notes' => 'Screenshot attached.',
            'evidence_files' => [['original_filename' => 'otp.png', 'mime_type' => 'image/png'], []],
            'status' => ValidationSubmissionStatus::Submitted,
        ]);

        $this->actingAs($participant)
            ->get(route('lab.records.show', $record))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Feature / Area:</strong> Registration',
                '<strong>Artifact Type:</strong> Test Plan',
                '<strong>AI Tool:</strong> ChatGPT',
                'The expiry claim is wrong.',
                'Decision: REPROMPT',
                'Screenshot attached.',
                'Uploaded Evidence',
                'otp.png <span class="text-muted">(image/png)</span>',
                'Evidence file <span class="text-muted">(file)</span>',
                'Validation record submitted for coach review.',
            ], false)
            ->assertDontSee('Lab record saved as a draft.')
            ->assertDontSee('Proceed to Activity 1 Submission');
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
