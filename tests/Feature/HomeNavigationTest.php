<?php

namespace Tests\Feature;

use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_platform_home_and_auth_links(): void
    {
        $this->get('/')->assertOk()->assertSee('DEVELOPER TRAINING PLATFORM')->assertSee('Log in')->assertSee('Create account');
    }

    public function test_legal_pages_are_public_and_linked_from_home(): void
    {
        $this->get(route('privacy-policy'))->assertOk()->assertSee('Privacy Policy')->assertSee('Google Drive')->assertSee('drive.file');
        $this->get(route('terms-of-service'))->assertOk()->assertSee('Terms of Service')->assertSee('Acceptable use')->assertSee('Google Drive');
        $this->get(route('home'))->assertOk()->assertSee(route('privacy-policy'))->assertSee(route('terms-of-service'));
    }

    public function test_authenticated_users_are_routed_to_role_homepages(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $this->actingAs($participant)->get('/')->assertRedirect(route('dashboard'));
        foreach (['coach', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/')->assertRedirect(route('coach.dashboard'));
        }
    }

    public function test_participant_navigation_places_profile_daily_log_and_logout_in_header(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);

        $html = $this->actingAs($participant)->get(route('dashboard'))->assertOk()->getContent();

        $profile = strpos($html, 'href="'.route('profile.show').'"');
        $dailyLog = strpos($html, 'href="'.route('daily-logs.index').'"');
        $logout = strpos($html, 'action="'.route('logout').'"');

        $this->assertNotFalse($profile);
        $this->assertNotFalse($dailyLog);
        $this->assertNotFalse($logout);
        $this->assertLessThan($dailyLog, $profile);
        $this->assertLessThan($logout, $dailyLog);
        // The participant setup action may also link to Profile when Drive is not ready.
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'href="'.route('profile.show').'"'));
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'href="'.route('daily-logs.index').'"'));
    }

    public function test_authenticated_pages_use_full_width_shell_while_auth_pages_remain_constrained(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach']);

        $dashboard = $this->actingAs($coach)->get(route('coach.participants'))->assertOk()->getContent();
        $this->assertStringContainsString('class="wrap app-shell"', $dashboard);
        $this->assertStringContainsString('.wrap.app-shell{max-width:none}', $dashboard);

        $this->post(route('logout'));
        $this->get(route('login'))->assertOk()->assertSee('class="wrap guest-shell"', false)->assertSee('max-width:1120px', false);
        $this->get(route('register'))->assertOk()->assertSee('class="wrap guest-shell"', false)->assertSee('max-width:1120px', false);
    }

    public function test_forwarded_public_host_is_preserved_for_guest_redirects(): void
    {
        $response = $this->withHeaders([
            'Host' => 'qa.local.test',
            'X-Forwarded-Host' => 'qa.local.test',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '127.0.0.1',
        ])->get('/dashboard');

        $this->assertSame('https://qa.local.test/login', $response->headers->get('Location'));
    }

    public function test_forwarded_public_host_is_preserved_for_authenticated_redirects(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->withHeaders([
            'Host' => 'qa.local.test',
            'X-Forwarded-Host' => 'qa.local.test',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '127.0.0.1',
        ])->get('/');

        $this->assertSame('https://qa.local.test/dashboard', $response->headers->get('Location'));
    }

    public function test_lab_activity_remains_available_separately(): void
    {
        $this->get(route('lab.activity'))->assertOk()->assertSee('Start Activity 1');
    }

    public function test_participant_dashboard_uses_configured_activities_and_needs_revision_feedback(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $week2 = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first();
        $activity = $week2->activities()->where('position', 2)->first();
        $submission = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'needs_revision']);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => [], 'submitted_at' => now()]);
        $version->reviews()->create(['reviewer_id' => User::factory()->create(['role' => 'coach'])->id, 'decision' => 'needs_revision', 'comments' => 'Please revise this activity.', 'reviewed_at' => now()]);
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee($activity->title)->assertSee('Revise');
        $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))->assertOk()->assertSee('Please revise this activity.');
    }

    public function test_participant_dashboard_shows_training_overview_before_week_cards(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('TRAINING OVERVIEW')
            ->assertSee('AI-Output Validation')
            ->assertSee('AI-Assisted Failure Analysis, Test Generation &amp; Regression Intelligence', false)
            ->assertSee('Sustain, Reuse, Reassess')
            ->assertSee('A Coach must review and pass the Gate before the next week becomes available.');
    }

    public function test_dashboard_disclosures_have_accessible_defaults_without_changing_content(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();
        ParticipantNotification::factory()->create(['user_id' => $participant->id, 'title' => 'Current work', 'body' => 'Complete your next activity.', 'resolved_at' => null]);

        $response = $this->actingAs($participant)->get(route('dashboard'))->assertOk();
        $response->assertSee('data-dashboard-disclosure', false)
            ->assertSee('aria-controls="dashboard-missing-content"', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('dashboard-training-overview-content', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('Complete your next activity.')
            ->assertSee('AI-Output Validation')
            ->assertSee('Gates &amp; progression', false)
            ->assertSee('TRAINING SESSION');
    }

    public function test_both_participant_paths_see_the_shared_qa_application_link_in_a_new_tab(): void
    {
        config(['services.qa_application.url' => 'https://membership.example.test/dashboard']);
        $this->seed(QaTrainingProgramSeeder::class);
        foreach (['manual', 'automation'] as $path) {
            $participant = User::factory()->create(['training_path' => $path]);
            $this->actingAs($participant)->get(route('dashboard'))->assertOk()
                ->assertSee('Open QA Application')
                ->assertSee('href="'.e(config('services.qa_application.url')).'"', false)
                ->assertSee('Fork QA Application')
                ->assertSee('href="https://github.com/fs-ely/membership-app/fork" target="_blank" rel="noopener noreferrer"', false)
                ->assertSee('target="_blank"', false)
                ->assertSee('rel="noopener noreferrer"', false);
        }
    }

    public function test_participant_dashboard_does_not_fallback_to_training_dashboard_when_qa_application_is_unconfigured(): void
    {
        config(['services.qa_application.url' => null]);
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()
            ->assertSee('QA Application Not Configured')
            ->assertDontSee('Open QA Application')
            ->assertDontSee('href="'.e(config('app.url')).'/dashboard" target="_blank"', false);
    }

    public function test_activity_one_step_keeps_six_check_validation_but_not_application_link(): void
    {
        $response = $this->post(route('start'), ['trainee_name' => 'QA Participant']);

        $response->assertOk()
            ->assertSee('Run 6-Check Validation')
            ->assertDontSee('Open QA Application')
            ->assertSee('AI GENERATED · QA VALIDATION · AI-TC-014');
    }

    public function test_validation_lab_copy_applies_to_both_qa_paths(): void
    {
        $this->get(route('lab.activity'))->assertOk()
            ->assertSee('Manual and Automation QA participants')
            ->assertDontSee('for manual testers');
    }

    public function test_participant_dashboard_and_gate_page_show_authoritative_gate_status_and_criteria(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $week2 = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first();
        $gate = $week2->gates()->with('criteria')->firstOrFail();
        $response = $this->actingAs($participant)->get(route('dashboard'));
        $response->assertOk()->assertSee($gate->title)->assertSee('Not ready')->assertSee('View Gate');
        $this->actingAs($participant)->get(route('training.gates.show', [$week2, $gate]))->assertOk()->assertSee($gate->criteria->first()->title)->assertSee('Awaiting Review');
    }

    public function test_combined_training_path_is_displayed_and_receives_both_path_requirements(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('Automation / Manual');
        $this->assertSame(['manual', 'automation', 'automation_manual'], $participant->eligibleTrainingPaths());
    }

    public function test_dashboard_shows_assigned_mentors_or_neutral_unassigned_state(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('Mentor')->assertSee('Not assigned');
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Ely Mentor']);
        $participant->assignedCoaches()->attach($coach);
        $this->actingAs($participant->fresh())->get(route('dashboard'))->assertOk()->assertSee('Ely Mentor');
    }

    public function test_training_session_shows_all_active_mock_interview_partners_and_statuses(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $first = User::factory()->create(['name' => 'Partner One', 'role' => 'participant']);
        $second = User::factory()->create(['name' => 'Partner Two', 'role' => 'participant']);
        $admin = User::factory()->create(['role' => 'admin']);
        WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $first->id), 'participant_two_id' => max($participant->id, $first->id), 'assigned_by_id' => $admin->id, 'status' => 'active']);
        WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $second->id), 'participant_two_id' => max($participant->id, $second->id), 'assigned_by_id' => $admin->id, 'status' => 'active']);

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()
            ->assertSee('MOCK INTERVIEW PARTNERS')
            ->assertSee('Partner One')
            ->assertSee('Partner Two')
            ->assertSee('Week 4: LOCKED')
            ->assertSee('follow up with your partners');
    }

    public function test_dashboard_contains_concise_qa_membership_feature_areas(): void
    {
        config(['services.qa_application.url' => 'https://membership.example.test']);
        $this->seed(QaTrainingProgramSeeder::class);
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
            ->assertSee('QA Membership feature areas')
            ->assertSee('Authentication &amp; Login', false)
            ->assertSee('OTP Verification')
            ->assertSee('Security &amp; Access Control', false)
            ->assertDontSee('JWT expiration');
    }
}
