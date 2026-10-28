<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use App\Services\ProgramEnrollmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JoinProgramTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_joins_a_curriculum_in_one_click_and_lands_on_its_page(): void
    {
        $this->travelTo('2026-11-10 09:30:00');
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->create(['name' => 'November run']);

        $this->actingAs($participant)
            ->post(route('training.programs.join', $program))
            ->assertRedirect(route('training.programs.show', $program))
            ->assertSessionHas('status', 'You joined November run.');

        $this->assertDatabaseHas('program_participants', [
            'program_id' => $program->id,
            'user_id' => $participant->id,
            'joined_at' => '2026-11-10 09:30:00',
            'added_by' => null,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->actingAs($participant)
            ->withSession(['status' => 'You joined November run.'])
            ->get(route('training.programs.show', $program))
            ->assertOk()
            ->assertSee('You joined November run.');
    }

    public function test_joining_moves_the_curriculum_from_available_curricula_to_my_curricula(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->upcoming()->create(['name' => 'December run']);
        Program::factory()->withWeeks(4)->completed()->create(['name' => 'October run']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My programs</h2>',
                'You are not in a program yet.',
                '>Available programs</h2>',
                '>December run</p>',
                '>Upcoming</span>',
                '<form method="POST" action="'.route('training.programs.join', $program).'">',
                'aria-label="Join December run"',
                '>Join</button>',
                '>Past programs</h2>',
            ], false)
            ->assertDontSee('href="'.route('training.programs.show', $program).'"', false)
            ->assertDontSee('October run');

        $this->actingAs($participant)->post(route('training.programs.join', $program));

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My programs</h2>',
                'href="'.route('training.programs.show', $program).'"',
                '>December run</a>',
                '>Available programs</h2>',
                'No programs are open to join right now.',
                '>Past programs</h2>',
            ], false)
            ->assertDontSee(route('training.programs.join', $program), false);
    }

    public function test_joining_twice_keeps_one_membership_and_opens_the_curriculum_page_again(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->create(['name' => 'November run']);

        $this->actingAs($participant)->post(route('training.programs.join', $program));
        $this->actingAs($participant)
            ->post(route('training.programs.join', $program))
            ->assertRedirect(route('training.programs.show', $program))
            ->assertSessionHas('status', 'You are already in November run.');

        $this->assertDatabaseCount('program_participants', 1);
    }

    public function test_the_join_service_keeps_an_existing_membership_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->create();
        $enrollments = app(ProgramEnrollmentService::class);
        $enrollments->addParticipants($program, [$participant->id], $admin);

        $membership = $enrollments->join($program, $participant);
        $sameMembership = $enrollments->join($program, $participant);

        $this->assertSame($membership->id, $sameMembership->id);
        $this->assertSame($admin->id, $membership->added_by);
        $this->assertDatabaseCount('program_participants', 1);
    }

    public function test_a_completed_curriculum_cannot_be_joined(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->completed()->create();

        $this->actingAs($participant)->post(route('training.programs.join', $program))->assertForbidden();

        $this->assertDatabaseCount('program_participants', 0);
    }

    public function test_a_curriculum_that_is_not_offered_cannot_be_joined_or_seen_in_available_curricula(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->upcoming()->create(['name' => 'Draft run', 'is_active' => false]);

        $this->actingAs($participant)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No programs are open to join right now.')
            ->assertDontSee('Draft run');

        $this->actingAs($participant)->post(route('training.programs.join', $program))->assertForbidden();

        $this->assertDatabaseCount('program_participants', 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('usersWhoCannotJoin')]
    public function test_only_active_participants_can_join(array $attributes): void
    {
        $user = User::factory()->create($attributes);
        $program = Program::factory()->withWeeks(4)->create();

        $this->actingAs($user)->post(route('training.programs.join', $program))->assertForbidden();

        $this->assertDatabaseCount('program_participants', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function usersWhoCannotJoin(): array
    {
        return [
            'coach' => [['role' => 'coach']],
            'admin' => [['role' => 'admin']],
            'inactive participant' => [['is_active' => false]],
        ];
    }
}
