<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Enums\ValidationCheckStatus;
use App\Enums\ValidationDecision;
use App\Enums\ValidationSubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\User;
use App\Models\ValidationSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DevelopmentAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_provisions_idempotent_development_accounts(): void
    {
        $this->seed();
        $this->seed();
        $this->assertDatabaseHas('users', ['email' => 'coach.dev@example.test', 'role' => 'coach']);
        $this->assertDatabaseHas('users', ['email' => 'admin.dev@example.test', 'role' => 'admin']);
        $this->assertSame(1, User::where('email', 'coach.dev@example.test')->count());
        $this->assertSame(1, User::where('email', 'admin.dev@example.test')->count());
        $this->assertSame(TrainingPath::Manual, User::query()->where('email', 'test@example.com')->sole()->training_path);
    }

    public function test_targeted_development_account_command_is_idempotent(): void
    {
        $this->artisan('app:ensure-development-accounts')->assertExitCode(0);
        $this->artisan('app:ensure-development-accounts')->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'admin.dev@example.test', 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['email' => 'coach.dev@example.test', 'role' => 'coach', 'is_active' => true]);
        $this->assertSame(1, User::where('email', 'admin.dev@example.test')->count());
        $this->assertSame(1, User::where('email', 'coach.dev@example.test')->count());
    }

    public function test_baseline_command_restores_reference_data_idempotently_without_deleting_users(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);

        $this->artisan('app:ensure-development-baseline')->assertExitCode(0);
        $activity = Program::query()->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $before = [
            'users' => User::count(),
            'submissions' => ActivitySubmission::count(),
            'programs' => Program::count(),
            'activities' => $activity->week->activities()->count(),
        ];

        $this->artisan('app:ensure-development-baseline')->assertExitCode(0);

        $this->assertSame($before['users'], User::count());
        $this->assertSame($before['submissions'], ActivitySubmission::count());
        $this->assertSame($before['programs'], Program::count());
        $this->assertSame($before['activities'], $activity->week->fresh()->activities()->count());
        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'user_id' => $participant->id]);
        $this->assertDatabaseHas('programs', ['name' => 'AI-Assisted QA Level 3', 'is_active' => true]);
    }

    public function test_baseline_command_refuses_production(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('app:ensure-development-baseline')->assertExitCode(1);
        $this->assertDatabaseCount('programs', 0);
    }

    public function test_development_account_command_refuses_production(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('app:ensure-development-accounts')->assertExitCode(1);
        $this->assertDatabaseMissing('users', ['email' => 'admin.dev@example.test']);
    }

    public function test_seeded_coach_and_admin_authenticate_and_reach_authorized_routes(): void
    {
        $this->seedAccountsWithEmployeeIds();
        foreach ([['coach.dev@example.test', 'CoachDev!2026'], ['admin.dev@example.test', 'AdminDev!2026']] as [$email, $password]) {
            $destination = str_starts_with($email, 'admin.') ? route('admin.dashboard') : route('coach.dashboard');
            $this->post(route('login.store'), compact('email', 'password'))->assertRedirect($destination);
            $this->get(route('coach.dashboard'))->assertOk();
            $this->get(route('coach.participants'))->assertOk();
            $this->get(route('coach.reviews'))->assertOk();
            $this->get(route('coach.gates'))->assertOk();
            $this->get(route('coach.reports'))->assertOk();
            $this->post(route('logout'))->assertRedirect(route('login'));
        }
    }

    public function test_public_registration_remains_participant_only(): void
    {
        $this->post(route('register.store'), [
            'employee_id' => 'FS-4001',
            'first_name' => 'Attempted',
            'last_name' => 'Coach',
            'email' => 'attempt@example.test',
            'job_role' => 'developer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'training_path' => 'manual',
            'role' => 'coach',
        ])->assertRedirect(route('profile.show'));
        $this->assertDatabaseHas('users', ['email' => 'attempt@example.test', 'role' => 'participant']);
    }

    public function test_authenticated_activity_one_lab_submission_keeps_participant_owner(): void
    {
        $this->seedAccountsWithEmployeeIds();
        $participant = User::where('email', 'test@example.com')->firstOrFail();
        $this->actingAs($participant)->get(route('dashboard'))->assertOk();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'trainee_name' => $participant->name, 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]));
        $this->actingAs($participant)->get(route('training.activities.show', [Program::first()->weeks()->where('week_number', 2)->first(), Program::first()->weeks()->where('week_number', 2)->first()->activities()->where('position', 1)->first()]))->assertOk()->assertSee('Open QA Lab');
        $this->actingAs($participant)->get(route('lab.activity'))->assertOk();
        $this->actingAs($participant)->post(route('start'), ['trainee_name' => $participant->name])
            ->assertOk()
            ->assertSee('Run 6-Check Validation')
            ->assertDontSee('Open QA Application')
            ->assertSee('AI-TC-014');
        $response = $this->actingAs($participant)->post(route('checklist.submit'), $this->labPayload())->assertRedirect();
        $result = $this->actingAs($participant)->get($response->headers->get('Location'));
        $result->assertOk()->assertSee('Proceed to Activity 1 Submission')->assertSee('training/weeks/1/activities/1/submission')->assertSee('validation_submission_id=');
        $this->assertDatabaseHas('validation_submissions', ['user_id' => $participant->id, 'artifact_name' => 'OTP validation response']);
        $this->assertDatabaseCount('activity_submissions', 0);
    }

    public function test_new_owned_lab_record_appears_in_an_existing_and_new_week_two_activity_form(): void
    {
        $this->seedAccountsWithEmployeeIds();
        $participant = User::where('email', 'test@example.com')->firstOrFail();
        $week = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        // Open/create the activity draft before the Lab record exists.
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertRedirect(route('training.activities.show', [$week, $activity]));
        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => []])->assertRedirect();

        $this->actingAs($participant)->withSession(['trainee_name' => $participant->name])->post(route('checklist.submit'), $this->labPayload(['artifact_code' => 'AI-TC-015']))->assertRedirect();
        $record = ValidationSubmission::latest('id')->firstOrFail();
        $this->assertSame($participant->id, $record->user_id);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('AI-TC-015')
            ->assertSee($record->artifact_name);
    }

    public function test_owned_lab_record_is_available_to_all_connected_week_two_activity_selectors(): void
    {
        $this->seedAccountsWithEmployeeIds();
        $participant = User::where('email', 'test@example.com')->firstOrFail();
        $week = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'trainee_name' => $participant->name, 'artifact_code' => 'AI-TC-015']));

        foreach ([1, 2, 3] as $position) {
            $activity = $week->activities()->where('position', $position)->firstOrFail();
            $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
                ->assertOk()
                ->assertSee('AI-TC-015')
                ->assertSee($record->artifact_name);
        }
    }

    public function test_artifact_name_is_required_trimmed_and_length_limited(): void
    {
        $participant = User::factory()->create();
        $this->actingAs($participant)->withSession(['trainee_name' => $participant->name]);

        $this->post(route('checklist.submit'), $this->labPayload(['artifact_name' => '   ']))->assertSessionHasErrors('artifact_name');
        $this->post(route('checklist.submit'), $this->labPayload(['artifact_name' => str_repeat('x', 161)]))->assertSessionHasErrors('artifact_name');
        $this->post(route('checklist.submit'), $this->labPayload(['artifact_name' => '  Named artifact  ']))->assertRedirect();

        $record = ValidationSubmission::latest('id')->firstOrFail();
        $this->assertSame('Named artifact', $record->artifact_name);
        $this->assertSame('AI-TC-014', $record->artifact_code);
    }

    public function test_participant_can_select_and_validate_a_custom_artifact_without_changing_six_checks(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'automation']);
        $content = "Create three login tests.\nExpected: invalid credentials are rejected.";

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'ChatGPT',
            'artifact_name' => 'Login rejection scenarios',
            'artifact_content' => $content,
        ])->assertOk()
            ->assertSee('Review your selected artifact')
            ->assertSee('AI-TC-015')
            ->assertSee('Login rejection scenarios')
            ->assertSee('ChatGPT')
            ->assertSee('Create three login tests.')
            ->assertSee('Expected: invalid credentials are rejected.');

        $this->actingAs($participant)->get(route('checklist'))
            ->assertOk()
            ->assertSee('Feature / Area:')
            ->assertSee('User Login / OTP Verification')
            ->assertSee('Test Scenario')
            ->assertSee('AI-TC-015');

        $response = $this->actingAs($participant)->post(route('checklist.submit'), $this->labPayload([
            'artifact_code' => 'AI-TC-999',
            'artifact_name' => 'Login rejection scenarios',
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'ChatGPT',
            'artifact_content' => $content,
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('validation_submissions', [
            'user_id' => $participant->id,
            'artifact_code' => 'AI-TC-015',
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'ChatGPT',
            'artifact_content' => $content,
        ]);
    }

    public function test_custom_artifact_code_follows_the_highest_existing_artifact_code(): void
    {
        ValidationSubmission::create($this->labPayload(['trainee_name' => 'Earlier participant', 'artifact_code' => 'AI-TC-041']));
        $participant = User::factory()->create(['role' => 'participant']);
        $artifact = [
            'trainee_name' => $participant->name,
            'feature_area' => 'Registration',
            'artifact_type' => 'Test Plan',
            'ai_tool' => 'ChatGPT',
            'artifact_name' => 'Registration plan',
            'artifact_content' => 'Plan content.',
        ];

        $this->actingAs($participant)->post(route('start'), $artifact)
            ->assertOk()
            ->assertSee('AI-TC-042')
            ->assertSessionHas('validation_artifact.artifact_code', 'AI-TC-042');
        $this->actingAs($participant)->post(route('start'), ['trainee_name' => $participant->name])
            ->assertOk()
            ->assertSessionHas('validation_artifact.artifact_code', 'AI-TC-014');
    }

    public function test_validation_lab_persists_exact_prompt_and_private_uploaded_evidence(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['role' => 'participant']);
        $prompt = "Check OTP expiry handling.\nUse the staging build and report the observed result.";

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'ChatGPT',
            'prompt' => $prompt,
            'artifact_name' => 'OTP expiry scenarios',
            'artifact_content' => 'The generated scenario checks expiry.',
        ])->assertOk();
        $this->actingAs($participant)->get(route('checklist'))
            ->assertOk()
            ->assertSee(e($prompt), false);

        $evidence = UploadedFile::fake()->create('otp-proof.png', 20, 'image/png');
        $this->actingAs($participant)->post(route('checklist.submit'), $this->labPayload([
            'decision' => 'revise',
            'evidence_files' => [$evidence],
        ]))->assertRedirect();

        $record = ValidationSubmission::latest('id')->firstOrFail();
        $this->assertSame($prompt, $record->prompt);
        $this->assertCount(1, $record->evidence_files);
        $metadata = $record->evidence_files[0];
        $this->assertSame('otp-proof.png', $metadata['original_filename']);
        $this->assertSame('local', $metadata['disk']);
        Storage::disk('local')->assertExists($metadata['path']);
    }

    public function test_lab_handoff_lists_any_owned_record_instead_of_hard_coded_artifact_code(): void
    {
        $this->seed();
        $participant = User::factory()->create(['role' => 'participant']);
        $lab = ValidationSubmission::create(array_merge($this->labPayload(), [
            'user_id' => $participant->id,
            'artifact_code' => 'AI-TC-027',
            'artifact_name' => 'Custom validation record',
            'trainee_name' => $participant->name,
        ]));
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [
                Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail(),
                Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->where('position', 1)->firstOrFail(),
            ]).'?validation_submission_id='.$lab->id)
            ->assertOk()
            ->assertSee('Custom validation record')
            ->assertSee('value="'.$lab->id.'"', false);
    }

    public function test_other_feature_and_artifact_type_require_and_store_trimmed_values(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $base = [
            'trainee_name' => $participant->name,
            'feature_area' => 'Other',
            'artifact_type' => 'Other',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Cancellation scenarios',
            'artifact_content' => 'AI-generated cancellation test scenario.',
        ];

        $this->actingAs($participant)->post(route('start'), $base)
            ->assertSessionHasErrors(['other_feature_area', 'other_artifact_type']);

        $this->actingAs($participant)->post(route('start'), $base + [
            'other_feature_area' => '   ',
            'other_artifact_type' => '   ',
        ])->assertSessionHasErrors(['other_feature_area', 'other_artifact_type']);

        $this->actingAs($participant)->post(route('start'), $base + [
            'other_feature_area' => '  Subscription cancellation  ',
            'other_artifact_type' => '  AI-generated exploratory test scenario  ',
        ])->assertOk()
            ->assertSee('Subscription cancellation')
            ->assertSee('AI-generated exploratory test scenario')
            ->assertDontSee('Feature / Area:</strong> Other', false)
            ->assertDontSee('Type:</strong> Other', false);

        $this->actingAs($participant)->post(route('checklist.submit'), $this->labPayload([
            'artifact_code' => 'tampered',
            'artifact_name' => 'Cancellation scenarios',
        ]))->assertRedirect();

        $this->assertDatabaseHas('validation_submissions', [
            'user_id' => $participant->id,
            'feature_area' => 'Subscription cancellation',
            'artifact_type' => 'AI-generated exploratory test scenario',
        ]);

        $this->actingAs($participant)->post(route('start'), array_merge($base, [
            'feature_area' => 'Membership',
            'artifact_type' => 'Test Plan',
            'other_feature_area' => 'Stale custom value',
            'other_artifact_type' => 'Stale custom type',
        ]))->assertOk()
            ->assertSee('Feature / Area:</strong> Membership', false)
            ->assertSee('Test Plan')
            ->assertDontSee('Stale custom value')
            ->assertDontSee('Stale custom type');
    }

    public function test_artifact_type_options_are_shared_with_validation_lab_and_activity_forms(): void
    {
        $this->seed();
        $participant = User::factory()->create(['role' => 'participant']);
        $response = $this->actingAs($participant)->get(route('lab.activity'))->assertOk();
        foreach (config('validation_lab.artifact_types') as $type) {
            $response->assertSee('value="'.e($type).'"', false);
        }

        $week = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'AI Output Error Detection')->firstOrFail();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('QA Validation Lab Record')
            ->assertSee('What Was Actually True')
            ->assertDontSee('Other Artifact Type')
            ->assertDontSee('Select the type of AI-generated QA artifact');
    }

    public function test_activity_artifact_type_other_is_trimmed_and_stored_as_the_actual_value(): void
    {
        $this->seed();
        $participant = User::factory()->create(['role' => 'participant']);
        $week = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'AI Output Error Detection')->firstOrFail();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), [
            'field_values' => ['artifact_type' => 'Other', 'artifact_type_other' => '  API contract test  '],
        ])->assertRedirect();

        $version = $activity->submissions()->where('user_id', $participant->id)->firstOrFail()->versions()->firstOrFail();
        $this->assertSame('API contract test', $version->field_values['artifact_type']);
        $this->assertArrayNotHasKey('artifact_type_other', $version->field_values);
    }

    public function test_activity_one_shows_only_authenticated_participant_lab_records(): void
    {
        $this->seed();
        $participant = User::factory()->create();
        $week = Program::first()->weeks()->where('week_number', 2)->first();
        $activity = $week->activities()->where('position', 1)->first();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $legacy = ValidationSubmission::create(['trainee_name' => 'legacy', 'artifact_code' => 'AI-TC-014', 'source_status' => 'pass', 'source_notes' => 'x', 'scope_status' => 'pass', 'scope_notes' => 'x', 'spec_status' => 'pass', 'spec_notes' => 'x', 'cross_check_status' => 'pass', 'cross_check_notes' => 'x', 'run_status' => 'pass', 'run_notes' => 'x', 'log_status' => 'pass', 'log_notes' => 'x', 'finding' => 'x', 'decision' => 'accept', 'evidence_notes' => 'x']);
        $owned = ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => 'owner', 'artifact_code' => 'AI-TC-014', 'artifact_name' => 'Owned artifact', 'source_status' => 'pass', 'source_notes' => 'x', 'scope_status' => 'pass', 'scope_notes' => 'x', 'spec_status' => 'pass', 'spec_notes' => 'x', 'cross_check_status' => 'pass', 'cross_check_notes' => 'x', 'run_status' => 'pass', 'run_notes' => 'x', 'log_status' => 'pass', 'log_notes' => 'x', 'finding' => 'x', 'decision' => 'accept', 'evidence_notes' => 'x']);
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->assertSee('value="'.$owned->id.'"', false)->assertSee('Owned artifact')->assertDontSee('value="'.$legacy->id.'"', false);
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]).'?validation_submission_id='.$owned->id)->assertOk()->assertSee('QA Validation Lab Record')->assertSee('View Lab Record')->assertSee('<dialog', false)->assertSee('SOURCE')->assertSee('SCOPE')->assertSee('SPEC')->assertSee('CROSS-CHECK')->assertSee('RUN')->assertSee('LOG')->assertSee('Finding')->assertSee('Decision')->assertSee('Owned artifact');
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]).'?validation_submission_id='.$legacy->id)->assertOk()->assertDontSee('Selected Lab Record #'.$legacy->id);
        $this->assertDatabaseCount('activity_submissions', 0);
    }

    public function test_first_attempt_at_a_later_week_two_activity_preselects_the_lab_record_used_earlier(): void
    {
        $this->seed();
        $participant = User::factory()->create();
        $week = Program::first()->weeks()->where('week_number', 2)->first();
        $activityOne = $week->activities()->where('position', 1)->first();
        $activityTwo = $week->activities()->where('position', 2)->first();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $newest = ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => 'owner', 'artifact_code' => 'AI-TC-101', 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]);
        $usedEarlier = ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => 'owner', 'artifact_code' => 'AI-TC-100', 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()->subDay()]);
        $earlier = ActivitySubmission::create(['activity_id' => $activityOne->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $earlier->versions()->create(['version_number' => 1, 'status' => 'submitted', 'submitted_at' => now(), 'validation_submission_id' => $usedEarlier->id, 'field_values' => []]);
        $edit = route('training.submission.edit', [$week, $activityTwo]);

        $this->actingAs($participant)->get($edit)->assertOk()
            ->assertSee('value="'.$usedEarlier->id.'" selected', false)
            ->assertDontSee('value="'.$newest->id.'" selected', false);

        $this->actingAs($participant)->get($edit.'?validation_submission_id='.$newest->id)->assertOk()
            ->assertSee('value="'.$newest->id.'" selected', false)
            ->assertDontSee('value="'.$usedEarlier->id.'" selected', false);

        $current = ActivitySubmission::create(['activity_id' => $activityTwo->id, 'user_id' => $participant->id, 'status' => 'draft']);
        $current->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => []]);

        $this->actingAs($participant)->get($edit)->assertOk()
            ->assertDontSee('value="'.$usedEarlier->id.'" selected', false);
    }

    public function test_participant_can_view_only_owned_lab_record_without_creating_submission(): void
    {
        $this->seed();
        $participant = User::factory()->create();
        $other = User::factory()->create();
        $owned = ValidationSubmission::create(array_merge(['user_id' => $participant->id], $this->labPayload(), ['trainee_name' => 'owner', 'prompt' => 'Prompt details', 'artifact_content' => 'Generated output']));
        $foreign = ValidationSubmission::create(array_merge(['user_id' => $other->id], $this->labPayload(), ['trainee_name' => 'other']));

        $details = $this->actingAs($participant)->get(route('lab.records.show', $owned))
            ->assertOk()
            ->assertSee('Validation recorded.')
            ->assertSee('View Details')
            ->assertSee('Checked')
            ->assertSee('Exact Prompt');
        $this->assertSame(6, substr_count($details->getContent(), 'View Details'));
        $this->actingAs($participant)->get(route('lab.records.show', $foreign))->assertForbidden();
        $this->assertDatabaseCount('activity_submissions', 0);
    }

    public function test_six_check_form_accepts_only_the_listed_check_statuses_and_decisions(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $this->actingAs($participant)->withSession(['trainee_name' => $participant->name]);

        $this->post(route('checklist.submit'), $this->labPayload(['source_status' => 'passed', 'log_status' => 'PASS', 'decision' => 'approve']))
            ->assertSessionHasErrors([
                'source_status' => 'The selected source status is invalid.',
                'log_status' => 'The selected log status is invalid.',
                'decision' => 'The selected decision is invalid.',
            ]);
        $this->assertDatabaseCount('validation_submissions', 0);

        $this->post(route('checklist.submit'), $this->labPayload(['scope_status' => 'issue', 'decision' => 'reprompt']))->assertRedirect();

        $record = ValidationSubmission::latest('id')->firstOrFail();
        $this->assertSame(ValidationCheckStatus::Pass, $record->source_status);
        $this->assertSame(ValidationCheckStatus::Issue, $record->scope_status);
        $this->assertSame(ValidationDecision::Reprompt, $record->decision);
    }

    public function test_selected_lab_record_dialog_shows_each_check_result_and_the_decision(): void
    {
        $this->seed();
        $participant = User::factory()->create();
        $week = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $record = ValidationSubmission::create(array_merge($this->labPayload(), [
            'user_id' => $participant->id,
            'trainee_name' => $participant->name,
            'scope_status' => 'issue',
            'spec_status' => null,
            'decision' => 'reprompt',
            'status' => ValidationSubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]));

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]).'?validation_submission_id='.$record->id)
            ->assertOk()
            ->assertSeeInOrder([
                '<dialog',
                '<strong>SOURCE</strong>', 'text-emerald-200">CONFIRMED / PASS</span>',
                '<strong>SCOPE</strong>', 'text-amber-200">ISSUE / GAP FOUND</span>',
                '<strong>SPEC</strong>', 'text-muted">NOT PROVIDED</span>',
                '<strong>CROSS-CHECK</strong>', 'text-emerald-200">CONFIRMED / PASS</span>',
                '<strong>Decision</strong><br>RE-PROMPT AI</p>',
            ], false);
    }

    public function test_lab_record_details_show_an_empty_state_for_missing_check_notes(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $record = ValidationSubmission::create([
            'user_id' => $participant->id,
            'trainee_name' => $participant->name,
            'artifact_code' => 'AI-TC-099',
            'source_status' => 'pass',
            'scope_status' => 'issue',
            'spec_status' => 'pass',
            'cross_check_status' => 'issue',
            'run_status' => 'pass',
            'log_status' => 'issue',
            'finding' => 'Finding',
            'decision' => 'revise',
            'evidence_notes' => 'Evidence',
        ]);

        $this->actingAs($participant)->get(route('lab.records.show', $record))
            ->assertOk()
            ->assertSee('No additional details recorded.')
            ->assertSee('SOURCE')
            ->assertSee('CROSS-CHECK')
            ->assertSee('ISSUE / GAP FOUND');
    }

    public function test_authorized_coach_sees_linked_artifact_name_in_submission_review(): void
    {
        $this->seed();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->where('position', 1)->firstOrFail();
        $lab = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'artifact_name' => 'Coach-visible artifact', 'trainee_name' => $participant->name]));
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = $submission->versions()->create([
            'version_number' => 1,
            'status' => 'submitted',
            'validation_submission_id' => $lab->id,
            'field_values' => [
                'feature_area' => 'User Login / OTP Verification',
                'environment' => 'Elycis staging environment',
                'ai_tool' => 'Claude',
                'artifact_type' => 'AI-generated test case',
                'exact_prompt' => "Create an OTP test case.\nPreserve the exact prompt.",
                'exact_ai_output' => "Test Case: Verify OTP expiration\nExpected result: two minutes.",
                'issue_caught' => 'The AI incorrectly changed the OTP validity period.',
                'action_taken' => 'Rejected the incorrect expected result and corrected the test case.',
            ],
            'participant_note' => 'Reviewed against the staging behavior.',
        ]);

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSee('Coach-visible artifact')
            ->assertSee('AI-TC-014')
            ->assertSee('View Validation Lab Record')
            ->assertSee('Feature / Area')
            ->assertSee('User Login / OTP Verification')
            ->assertSee('Exact Prompt')
            ->assertSee('Create an OTP test case.')
            ->assertSee('Exact AI Output')
            ->assertSee('Verify OTP expiration')
            ->assertSee('Issue Caught')
            ->assertSee('Action Taken')
            ->assertSee('Reviewed against the staging behavior.')
            ->assertSee('submission_version='.$version->id, false)
            ->assertDontSee('"feature_area"');

        $this->actingAs($coach)->get(route('lab.records.show', [$lab, 'submission_version' => $version->id]))
            ->assertOk()
            ->assertSee('SOURCE')
            ->assertSee('Finding');

        $foreign = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => User::factory()->create()->id, 'trainee_name' => 'Other participant']));
        $this->actingAs($coach)->get(route('lab.records.show', [$foreign, 'submission_version' => $version->id]))->assertForbidden();

        $legacy = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => null, 'trainee_name' => 'Legacy participant']));
        $legacyVersion = $submission->versions()->create(['version_number' => 2, 'status' => 'submitted', 'validation_submission_id' => $legacy->id, 'field_values' => []]);
        $this->actingAs($coach)->get(route('coach.reviews.show', $legacyVersion))->assertOk()->assertSee('No Validation Lab record linked.');
        $this->actingAs($coach)->get(route('lab.records.show', [$legacy, 'submission_version' => $legacyVersion->id]))->assertForbidden();
    }

    /**
     * Seed the database and give the seeded accounts an employee ID, which they lack until the demo accounts are
     * rewritten, so signing in does not stop at the employee ID prompt.
     */
    private function seedAccountsWithEmployeeIds(): void
    {
        $this->seed();

        User::query()->whereNull('employee_id')->each(function (User $user): void {
            $user->update(['employee_id' => "EMP-SEED-{$user->id}"]);
        });
    }

    private function labPayload(array $overrides = []): array
    {
        $data = ['artifact_code' => 'AI-TC-014', 'artifact_name' => 'OTP validation response', 'finding' => 'Issue', 'decision' => 'revise', 'evidence_notes' => 'Proof'];
        foreach (['source', 'scope', 'spec', 'cross_check', 'run', 'log'] as $check) {
            $data[$check.'_status'] = 'pass';
            $data[$check.'_notes'] = 'Checked';
        }

        return array_merge($data, $overrides);
    }
}
