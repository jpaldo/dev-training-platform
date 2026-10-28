<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ParticipantDashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_landing_page_lists_current_curricula_under_my_curricula_and_completed_ones_under_past_curricula(): void
    {
        $participant = User::factory()->create();
        $active = Program::factory()->withWeeks(2)->withParticipants($participant)->create(['name' => 'Active run']);
        $upcoming = Program::factory()->withWeeks(2)->upcoming()->withParticipants($participant)->create(['name' => 'Upcoming run']);
        $completed = Program::factory()->withWeeks(2)->completed()->withParticipants($participant)->create(['name' => 'Completed run']);
        $notJoined = Program::factory()->withWeeks(2)->create(['name' => 'Someone else’s run']);

        $html = $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My curricula</h2>',
                'href="'.route('training.programs.show', $active).'"',
                '>Active run</a>',
                $active->dateRangeLabel(),
                '>Active</span>',
                'href="'.route('training.programs.show', $upcoming).'"',
                '>Upcoming run</a>',
                '>Upcoming</span>',
                '>Available curricula</h2>',
                '>Someone else’s run</p>',
                '>Past curricula</h2>',
                'href="'.route('training.programs.show', $completed).'"',
                '>Completed run</a>',
                '>Completed</span>',
            ], false)
            ->assertDontSee('href="'.route('training.programs.show', $notJoined).'"', false)
            ->assertDontSee('You are not in a curriculum yet.')
            ->assertDontSee('No past curricula yet.')
            ->getContent();

        $this->assertSame(1, substr_count($html, '>Completed run</a>'));
        $this->assertSame(1, substr_count($html, '>Someone else’s run</p>'));

        $this->actingAs($participant)->get(route('training.programs.show', $active))->assertOk();
        $this->actingAs($participant)->get(route('training.programs.show', $completed))->assertOk();
    }

    public function test_past_curricula_list_the_latest_curriculum_first(): void
    {
        $participant = User::factory()->create();
        Program::factory()->withWeeks(4, today()->subWeeks(12))->withParticipants($participant)->create(['name' => 'Earlier run']);
        Program::factory()->withWeeks(4, today()->subWeeks(6))->withParticipants($participant)->create(['name' => 'Later run']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['>Past curricula</h2>', '>Later run</a>', '>Earlier run</a>'], false);
    }

    public function test_a_participant_in_no_curriculum_sees_both_empty_states(): void
    {
        $participant = User::factory()->create();
        $notJoined = Program::factory()->withWeeks(1)->create(['name' => 'Open run']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My curricula</h2>',
                'You are not in a curriculum yet.',
                '>Available curricula</h2>',
                '>Open run</p>',
                '>Past curricula</h2>',
                'No past curricula yet.',
            ], false)
            ->assertDontSee('href="'.route('training.programs.show', $notJoined).'"', false);
    }

    public function test_the_landing_page_keeps_the_attendance_card_and_has_no_week_tabs(): void
    {
        $participant = User::factory()->create(['first_name' => 'Hal', 'last_name' => 'Hybrid']);
        Program::factory()->withWeeks(2)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Welcome, Hal', 'ATTENDANCE', 'NOT CHECKED IN', 'CHECK IN'], false)
            ->assertDontSee('CURRICULUM</div>', false)
            ->assertDontSee('Start at Week 2.')
            ->assertDontSee('data-page-tabs', false)
            ->assertDontSee('No training program is active yet.');
    }
}
