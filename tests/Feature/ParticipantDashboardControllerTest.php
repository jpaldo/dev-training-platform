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
                '>My programs</h2>',
                'href="'.route('training.programs.show', $active).'"',
                '>Active run</a>',
                $active->dateRangeLabel(),
                '>Active</span>',
                'href="'.route('training.programs.show', $upcoming).'"',
                '>Upcoming run</a>',
                '>Upcoming</span>',
                '>Available programs</h2>',
                '>Someone else’s run</p>',
                '>Past programs</h2>',
                'href="'.route('training.programs.show', $completed).'"',
                '>Completed run</a>',
                '>Completed</span>',
            ], false)
            ->assertDontSee('href="'.route('training.programs.show', $notJoined).'"', false)
            ->assertDontSee('You are not in a program yet.')
            ->assertDontSee('No past programs yet.')
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
            ->assertSeeInOrder(['>Past programs</h2>', '>Later run</a>', '>Earlier run</a>'], false);
    }

    public function test_a_participant_in_no_curriculum_sees_both_empty_states(): void
    {
        $participant = User::factory()->create();
        $notJoined = Program::factory()->withWeeks(1)->create(['name' => 'Open run']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My programs</h2>',
                'You are not in a program yet.',
                '>Available programs</h2>',
                '>Open run</p>',
                '>Past programs</h2>',
                'No past programs yet.',
            ], false)
            ->assertDontSee('href="'.route('training.programs.show', $notJoined).'"', false);
    }

    public function test_the_landing_page_has_no_attendance_card_and_no_week_tabs(): void
    {
        $participant = User::factory()->create(['first_name' => 'Hal', 'last_name' => 'Hybrid']);
        Program::factory()->withWeeks(2)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Welcome, Hal', 'Keep track of what needs attention and open your programs.', '>Mentor</span>', '>My programs</h2>'], false)
            ->assertDontSee('ATTENDANCE')
            ->assertDontSee('CHECK IN')
            ->assertDontSee('CHECK OUT')
            ->assertDontSee('Check in')
            ->assertDontSee('live-attendance-elapsed', false)
            ->assertDontSee('PROGRAM</div>', false)
            ->assertDontSee('Start at Week 2.')
            ->assertDontSee('data-page-tabs', false)
            ->assertDontSee('No training program is active yet.');
    }

    public function test_the_participant_check_in_page_and_actions_no_longer_exist(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get('/attendance')->assertNotFound();
        $this->actingAs($participant)->post('/attendance/check-in')->assertNotFound();
        $this->actingAs($participant)->post('/attendance/check-out')->assertNotFound();
    }
}
