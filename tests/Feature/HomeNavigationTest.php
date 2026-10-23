<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_pages_are_public_and_linked_from_the_login_page(): void
    {
        $this->get(route('privacy-policy'))->assertOk()->assertSee('Privacy Policy')->assertSee('Google Drive')->assertSee('drive.file');
        $this->get(route('terms-of-service'))->assertOk()->assertSee('Terms of Service')->assertSee('Acceptable use')->assertSee('Google Drive');
        $this->get(route('login'))->assertOk()->assertSee(route('privacy-policy'))->assertSee(route('terms-of-service'));
    }

    public function test_participant_sidebar_contains_profile_and_logout(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);

        $html = $this->actingAs($participant)->get(route('dashboard'))->assertOk()->getContent();
        $sidebar = substr($html, strpos($html, 'id="app-sidebar"'), strpos($html, '</aside>') - strpos($html, 'id="app-sidebar"'));

        $this->assertStringContainsString('href="'.route('profile.show').'"', $sidebar);
        $this->assertStringContainsString('action="'.route('logout').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('admin.dashboard').'"', $sidebar);
        $this->assertStringNotContainsString('href="'.route('coach.dashboard').'"', $sidebar);
    }

    public function test_sidebar_shows_role_specific_groups_and_marks_current_page(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $coachHtml = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.participants'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('coach.participants'), '/').'"\s+aria-current="page"/', $coachHtml);
        $this->assertDoesNotMatchRegularExpression('/href="'.preg_quote(route('coach.reviews'), '/').'"\s+aria-current="page"/', $coachHtml);
        $this->assertStringNotContainsString('href="'.route('admin.settings.index').'"', $coachHtml);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.dashboard'))->assertOk()
            ->assertSee('href="'.route('admin.settings.index').'"', false)
            ->assertSee('href="'.route('coach.reviews').'"', false);
    }

    public function test_authenticated_pages_use_full_width_shell_while_auth_pages_remain_constrained(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.participants'))->assertOk()
            ->assertSee('<div class="px-[clamp(1rem,3vw,3rem)] py-6">', false)
            ->assertDontSee('max-w-280', false);

        $this->post(route('logout'));
        $this->get(route('login'))->assertOk()->assertSee('<div class="mx-auto max-w-280 px-5.5 py-6">', false);
        $this->get(route('register'))->assertOk()->assertSee('<div class="mx-auto max-w-280 px-5.5 py-6">', false);
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
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->withHeaders([
            'Host' => 'qa.local.test',
            'X-Forwarded-Host' => 'qa.local.test',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '127.0.0.1',
        ])->get('/');

        $this->assertSame('https://qa.local.test/dashboard', $response->headers->get('Location'));
    }

    public function test_participant_dashboard_uses_configured_activities_and_needs_revision_feedback(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $week1 = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first();
        $activity = $week1->activities()->where('position', 2)->first();
        $session = TrainingSession::factory()->forWeek($week1)->withParticipants($participant)->create();
        $submission = ActivitySubmission::factory()->for($activity)->create(['user_id' => $participant->id, 'status' => 'needs_revision']);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => [], 'submitted_at' => now()]);
        $version->reviews()->create(['reviewer_id' => User::factory()->create(['role' => 'coach'])->id, 'decision' => 'needs_revision', 'comments' => 'Please revise this activity.', 'reviewed_at' => now()]);
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee($activity->title)->assertSee('Revise');
        $this->actingAs($participant)->get(route('training.submission.edit', [$session, $week1, $activity]))->assertOk()->assertSee('Please revise this activity.');
    }

    public function test_participant_dashboard_shows_training_overview_before_week_cards(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeInOrder([
                'TRAINING OVERVIEW',
                '>How this training works</h2>',
                'Throughout the training, you will practice how to:',
                'Gates &amp; progression',
                'A Mentor must review the weekly checklist and mark the week completed before the next week becomes available.',
            ], false)
            ->assertDontSee('AI-Assisted QA Level 3')
            ->assertDontSee('AI-Output Validation')
            ->assertDontSee('Training journey');
    }

    public function test_dashboard_disclosure_and_tabs_have_accessible_defaults_without_changing_content(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        ParticipantNotification::factory()->create(['user_id' => $participant->id, 'title' => 'Current work', 'body' => 'Complete your next activity.', 'resolved_at' => null]);

        $response = $this->actingAs($participant)->get(route('dashboard'))->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/id="dashboard-tab-week-1"[^>]*aria-selected="true"/', $html);
        $this->assertMatchesRegularExpression('/id="dashboard-tab-overview"[^>]*aria-selected="false"/', $html);
        $this->assertMatchesRegularExpression('/id="dashboard-panel-week-1"(?:(?!hidden)[^>])*>/', $html);
        $this->assertMatchesRegularExpression('/id="dashboard-panel-overview"[^>]*\shidden\b/', $html);
        $response->assertSee('data-dashboard-disclosure', false)
            ->assertSee('aria-controls="dashboard-missing-content"', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('Complete your next activity.')
            ->assertSee('How this training works')
            ->assertSee('Gates &amp; progression', false)
            ->assertSee('TRAINING SESSION');
    }

    public function test_participant_dashboard_and_gate_page_show_authoritative_gate_status_and_criteria(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $week1 = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first();
        $gate = $week1->gates()->with('criteria')->firstOrFail();
        $response = $this->actingAs($participant)->get(route('dashboard'));
        $response->assertOk()->assertSee($gate->title)->assertSee('Not ready')->assertSee('View Gate');
        $this->actingAs($participant)->get(route('training.gates.show', [$week1, $gate]))->assertOk()->assertSee($gate->criteria->first()->title)->assertSee('Awaiting Review');
    }

    public function test_dashboard_shows_assigned_mentors_or_neutral_unassigned_state(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('Mentor')->assertSee('Not assigned');
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Ely Mentor']);
        $participant->assignedCoaches()->attach($coach);
        $this->actingAs($participant->fresh())->get(route('dashboard'))->assertOk()->assertSee('Ely Mentor');
    }
}
