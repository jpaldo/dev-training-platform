<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumOfferingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_withdraws_an_offered_curriculum_its_participants_stay_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->withParticipants($participant)->create(['name' => 'Spring Cohort']);

        $this->actingAs($admin)
            ->put(route('admin.programs.offered.update', $program), ['offered' => '0'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $program).'#overview')
            ->assertSessionHas('status', 'Spring Cohort is no longer offered to participants.');

        $this->assertFalse($program->fresh()->is_active);
        $this->assertTrue($program->includesParticipant($participant));
        $this->actingAs($participant)->get(route('training.programs.show', $program))->assertOk();

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame('curriculum_withdrawn', $audit->action);
        $this->assertSame([
            'program_id' => $program->id,
            'changes' => ['is_active' => ['from' => true, 'to' => false]],
        ], $audit->metadata);
    }

    public function test_admin_offers_a_curriculum_and_participants_can_then_join_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $program = Program::factory()->withWeeks(4)->create(['name' => 'Spring Cohort', 'is_active' => false]);

        $this->actingAs($admin)
            ->put(route('admin.programs.offered.update', $program), ['offered' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $program).'#overview')
            ->assertSessionHas('status', 'Spring Cohort is offered to participants.');

        $this->assertTrue($program->fresh()->is_active);
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_offered', $audit->action);
        $this->assertSame([
            'program_id' => $program->id,
            'changes' => ['is_active' => ['from' => false, 'to' => true]],
        ], $audit->metadata);

        $this->actingAs($participant)
            ->post(route('training.programs.join', $program))
            ->assertRedirect(route('training.programs.show', $program));
        $this->assertTrue($program->includesParticipant($participant));
    }

    public function test_saving_the_current_state_changes_nothing_and_records_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'Spring Cohort']);

        $this->actingAs($admin)
            ->put(route('admin.programs.offered.update', $program), ['offered' => '1'])
            ->assertRedirect(route('admin.programs.show', $program).'#overview')
            ->assertSessionHas('status', 'Spring Cohort is offered to participants.');

        $this->assertTrue($program->fresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_overview_tab_shows_whether_the_curriculum_is_offered_and_its_button_posts_the_opposite(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $offered = Program::factory()->withWeeks(1)->create();
        $notOffered = Program::factory()->withWeeks(1)->create(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $offered))
            ->assertOk()
            ->assertSeeInOrder([
                '>Offered to participants</h3>',
                '>Offered</span>',
                'action="'.route('admin.programs.offered.update', $offered).'"',
                '<input type="hidden" name="_method" value="PUT">',
                '<input type="hidden" name="offered" value="0">',
                '>Withdraw Program</button>',
            ], false);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $notOffered))
            ->assertOk()
            ->assertSeeInOrder([
                '>Offered to participants</h3>',
                '>Not offered</span>',
                'action="'.route('admin.programs.offered.update', $notOffered).'"',
                '<input type="hidden" name="offered" value="1">',
                '>Offer Program</button>',
            ], false);
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidOfferings')]
    public function test_invalid_input_returns_to_the_overview_and_changes_nothing(array $input, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.programs.offered.update', $program), $input)
            ->assertRedirect(route('admin.programs.show', $program).'#overview')
            ->assertSessionHasErrors(['offered' => $message]);

        $this->assertTrue($program->fresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidOfferings(): array
    {
        return [
            'missing' => [[], 'The offered field is required.'],
            'not a boolean' => [['offered' => 'maybe'], 'The offered field must be true or false.'],
        ];
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $program = Program::factory()->create();
        $url = route('admin.programs.offered.update', $program);

        $this->put($url, ['offered' => '0'])->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->put($url, ['offered' => '0'])->assertForbidden();
        }

        $this->assertTrue($program->fresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
