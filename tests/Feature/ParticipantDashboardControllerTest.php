<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ParticipantDashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_landing_page_lists_current_sessions_under_my_trainings_and_completed_ones_under_past_trainings(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $active = TrainingSession::factory()->for($program)->active()->withParticipants($participant)->create(['title' => 'Active run']);
        $upcoming = TrainingSession::factory()->for($program)->upcoming()->withParticipants($participant)->create(['title' => 'Upcoming run']);
        $completed = TrainingSession::factory()->for($program)->completed()->withParticipants($participant)->create(['title' => 'Completed run']);
        TrainingSession::factory()->for($program)->active()->create(['title' => 'Someone else’s run']);

        $html = $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My trainings</h2>',
                'href="'.route('training.sessions.show', $active).'"',
                '>Active run</a>',
                'L4 Accelerator Training',
                $active->dateRangeLabel(),
                '>Active</span>',
                'href="'.route('training.sessions.show', $upcoming).'"',
                '>Upcoming run</a>',
                '>Upcoming</span>',
                '>Past trainings</h2>',
                'href="'.route('training.sessions.show', $completed).'"',
                '>Completed run</a>',
                '>Completed</span>',
            ], false)
            ->assertDontSee('Someone else’s run')
            ->assertDontSee('You are not in a training yet.')
            ->assertDontSee('No past trainings yet.')
            ->getContent();

        $this->assertSame(1, substr_count($html, '>Completed run</a>'));

        $this->actingAs($participant)->get(route('training.sessions.show', $active))->assertOk();
        $this->actingAs($participant)->get(route('training.sessions.show', $completed))->assertOk();
    }

    public function test_past_trainings_list_the_latest_session_first(): void
    {
        $participant = User::factory()->create();
        TrainingSession::factory()->withParticipants($participant)->create(['title' => 'Earlier run', 'starts_on' => today()->subWeeks(12), 'ends_on' => today()->subWeeks(8)]);
        TrainingSession::factory()->withParticipants($participant)->create(['title' => 'Later run', 'starts_on' => today()->subWeeks(6), 'ends_on' => today()->subWeeks(2)]);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['>Past trainings</h2>', '>Later run</a>', '>Earlier run</a>'], false);
    }

    public function test_a_participant_in_no_session_sees_both_empty_states(): void
    {
        $participant = User::factory()->create();
        TrainingSession::factory()->create();

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My trainings</h2>',
                'You are not in a training yet.',
                '>Past trainings</h2>',
                'No past trainings yet.',
            ], false)
            ->assertDontSee('/training/sessions/', false);
    }

    public function test_the_landing_page_keeps_the_attendance_card_and_has_no_week_tabs(): void
    {
        $participant = User::factory()->create(['first_name' => 'Hal', 'last_name' => 'Hybrid']);
        TrainingSession::factory()->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Welcome, Hal', 'ATTENDANCE', 'NOT CHECKED IN', 'CHECK IN'], false)
            ->assertDontSee('TRAINING SESSION</div>', false)
            ->assertDontSee('Start at Week 2.')
            ->assertDontSee('data-page-tabs', false)
            ->assertDontSee('No training program is active yet.');
    }
}
