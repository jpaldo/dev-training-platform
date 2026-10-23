<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JoinTrainingSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_joins_a_session_in_one_click_and_lands_on_its_page(): void
    {
        $this->travelTo('2026-11-10 09:30:00');
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->active()->create(['title' => 'November run']);

        $this->actingAs($participant)
            ->post(route('training.sessions.join', $session))
            ->assertRedirect(route('training.sessions.show', $session))
            ->assertSessionHas('status', 'You joined November run.');

        $this->assertDatabaseHas('training_session_participants', [
            'training_session_id' => $session->id,
            'user_id' => $participant->id,
            'joined_at' => '2026-11-10 09:30:00',
            'added_by' => null,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->actingAs($participant)
            ->withSession(['status' => 'You joined November run.'])
            ->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSee('You joined November run.');
    }

    public function test_joining_moves_the_session_from_available_trainings_to_my_trainings(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->upcoming()->create(['title' => 'December run']);
        TrainingSession::factory()->completed()->create(['title' => 'October run']);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My trainings</h2>',
                'You are not in a training yet.',
                '>Available trainings</h2>',
                '>December run</p>',
                '>Upcoming</span>',
                '<form method="POST" action="'.route('training.sessions.join', $session).'">',
                'aria-label="Join December run"',
                '>Join</button>',
                '>Past trainings</h2>',
            ], false)
            ->assertDontSee('href="'.route('training.sessions.show', $session).'"', false)
            ->assertDontSee('October run');

        $this->actingAs($participant)->post(route('training.sessions.join', $session));

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                '>My trainings</h2>',
                'href="'.route('training.sessions.show', $session).'"',
                '>December run</a>',
                '>Available trainings</h2>',
                'No trainings are open to join right now.',
                '>Past trainings</h2>',
            ], false)
            ->assertDontSee(route('training.sessions.join', $session), false);
    }

    public function test_joining_twice_keeps_one_membership_and_opens_the_session_page_again(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->active()->create(['title' => 'November run']);

        $this->actingAs($participant)->post(route('training.sessions.join', $session));
        $this->actingAs($participant)
            ->post(route('training.sessions.join', $session))
            ->assertRedirect(route('training.sessions.show', $session))
            ->assertSessionHas('status', 'You are already in November run.');

        $this->assertDatabaseCount('training_session_participants', 1);
    }

    public function test_the_join_service_keeps_an_existing_membership_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->create();
        $sessions = app(TrainingSessionService::class);
        $sessions->addParticipants($session, [$participant->id], $admin);

        $membership = $sessions->join($session, $participant);
        $sameMembership = $sessions->join($session, $participant);

        $this->assertSame($membership->id, $sameMembership->id);
        $this->assertSame($admin->id, $membership->added_by);
        $this->assertDatabaseCount('training_session_participants', 1);
    }

    public function test_a_completed_session_cannot_be_joined(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->completed()->create();

        $this->actingAs($participant)->post(route('training.sessions.join', $session))->assertForbidden();

        $this->assertDatabaseCount('training_session_participants', 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('usersWhoCannotJoin')]
    public function test_only_active_participants_can_join(array $attributes): void
    {
        $user = User::factory()->create($attributes);
        $session = TrainingSession::factory()->active()->create();

        $this->actingAs($user)->post(route('training.sessions.join', $session))->assertForbidden();

        $this->assertDatabaseCount('training_session_participants', 0);
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
