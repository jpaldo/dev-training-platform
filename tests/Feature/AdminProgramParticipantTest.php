<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProgramParticipantTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_adds_two_participants_who_are_listed_with_todays_joined_date_and_audited(): void
    {
        $this->travelTo('2026-11-10 09:30:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $ava = User::factory()->create(['name' => 'Ava Santos', 'employee_id' => 'EMP-0001']);
        $ben = User::factory()->create(['name' => 'Ben Cruz', 'employee_id' => 'EMP-0002']);

        $this->actingAs($admin)
            ->post(route('admin.programs.participants.store', $program), ['user_ids' => [(string) $ben->id, (string) $ava->id]])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $program).'#participants')
            ->assertSessionHas('status', 'Added 2 participants.');

        foreach ([$ava, $ben] as $participant) {
            $this->assertDatabaseHas('program_participants', [
                'program_id' => $program->id,
                'user_id' => $participant->id,
                'added_by' => $admin->id,
                'joined_at' => '2026-11-10 09:30:00',
            ]);
        }

        $audits = AdminAuditLog::query()->orderBy('target_user_id')->get();
        $this->assertSame([$ava->id, $ben->id], $audits->pluck('target_user_id')->all());
        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame('program_participant_added', $audit->action);
            $this->assertSame(['program_id' => $program->id], $audit->metadata);
        }

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('Participants (2)')
            ->assertSeeInOrder(['Ava Santos', 'EMP-0001', '10 Nov 2026', 'Ben Cruz', 'EMP-0002', '10 Nov 2026']);
    }

    public function test_adding_someone_already_in_the_curriculum_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $member = User::factory()->create();
        $program->participants()->attach($member, ['joined_at' => '2026-11-09 10:00:00']);
        $newcomer = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.programs.index'))
            ->post(route('admin.programs.participants.store', $program), ['user_ids' => [$newcomer->id, $member->id]])
            ->assertRedirect(route('admin.programs.show', $program).'#participants')
            ->assertSessionHasErrorsIn('participants', ['user_ids.1' => 'The selected participant is already in this program.']);

        $this->assertSame([$member->id], $program->participants()->pluck('users.id')->all());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_coaches_admins_and_inactive_participants_cannot_be_added(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $users = [
            User::factory()->create(['role' => 'coach']),
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['is_active' => false]),
        ];

        foreach ($users as $user) {
            $this->actingAs($admin)
                ->post(route('admin.programs.participants.store', $program), ['user_ids' => [$user->id]])
                ->assertSessionHasErrorsIn('participants', ['user_ids.0' => 'Only active participants can be added to a program.']);
        }

        $this->assertDatabaseCount('program_participants', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_adding_requires_at_least_one_participant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.programs.participants.store', $program), ['user_ids' => []])
            ->assertSessionHasErrorsIn('participants', ['user_ids' => 'Choose at least one participant to add.']);

        $this->assertDatabaseCount('program_participants', 0);
    }

    public function test_the_participants_tab_offers_only_active_participants_not_in_the_curriculum_and_a_remove_button_per_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $member = User::factory()->create(['name' => 'Mia Member', 'employee_id' => 'EMP-0100']);
        $program->participants()->attach($member, ['joined_at' => now()]);
        $otherProgramMember = User::factory()->create(['name' => 'Ava Santos', 'employee_id' => 'EMP-0001']);
        Program::factory()->create()->participants()->attach($otherProgramMember, ['joined_at' => now()]);
        User::factory()->create(['name' => 'Cara Reyes', 'employee_id' => null]);
        User::factory()->create(['name' => 'Ian Inactive', 'is_active' => false]);
        User::factory()->create(['name' => 'Cole Coach', 'role' => 'coach']);

        $response = $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('action="'.route('admin.programs.participants.store', $program).'"', false)
            ->assertSee('<label for="program-participant-ids" class="text-sm font-extrabold">Name (Employee ID)</label>', false)
            ->assertSeeInOrder(['<option value="'.$otherProgramMember->id.'"', 'Ava Santos (EMP-0001)', 'Cara Reyes (no employee ID)'], false)
            ->assertDontSee('Mia Member (EMP-0100)')
            ->assertDontSee('Ian Inactive')
            ->assertSee('action="'.route('admin.programs.participants.destroy', [$program, $member]).'" data-confirm="Remove Mia Member from this curriculum? Their work is kept."', false)
            ->assertSee('aria-label="Remove Mia Member"', false);

        $this->assertStringContainsString('name="_method" value="DELETE"', $response->getContent());
        $this->assertStringNotContainsString('Cole Coach', Str::betweenFirst($response->getContent(), 'id="program-participant-ids"', '</select>'));
    }

    public function test_the_add_form_is_replaced_by_a_note_when_every_active_participant_is_in_the_curriculum(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $program->participants()->attach(User::factory()->create(), ['joined_at' => now()]);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('Every active participant is already in this curriculum.')
            ->assertDontSee('action="'.route('admin.programs.participants.store', $program).'"', false)
            ->assertDontSee('name="user_ids[]"', false);
    }

    public function test_removing_a_participant_deletes_only_their_membership_keeps_their_work_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $otherProgram = Program::factory()->create();
        $leaving = User::factory()->create(['name' => 'Lee Leaving']);
        $staying = User::factory()->create();
        $program->participants()->attach([$leaving->id, $staying->id], ['joined_at' => now()]);
        $otherProgram->participants()->attach($leaving, ['joined_at' => now()]);
        $submission = ActivitySubmission::factory()
            ->for(Activity::factory()->for(TrainingWeek::factory()->for($program), 'week'))
            ->for($leaving, 'participant')
            ->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('admin.programs.participants.destroy', [$program, $leaving]))
            ->assertRedirect(route('admin.programs.show', $program).'#participants')
            ->assertSessionHas('status', 'Removed Lee Leaving from the program.');

        $this->assertSame([$staying->id], $program->participants()->pluck('users.id')->all());
        $this->assertSame([$leaving->id], $otherProgram->participants()->pluck('users.id')->all());
        $this->assertModelExists($leaving);
        $this->assertModelExists($submission);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($leaving->id, $audit->target_user_id);
        $this->assertSame('program_participant_removed', $audit->action);
        $this->assertSame(['program_id' => $program->id], $audit->metadata);
    }

    public function test_removing_someone_who_is_not_in_the_curriculum_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $outsider = User::factory()->create();
        Program::factory()->create()->participants()->attach($outsider, ['joined_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('admin.programs.participants.destroy', [$program, $outsider]))
            ->assertNotFound();

        $this->assertDatabaseCount('program_participants', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $program = Program::factory()->create();
        $member = User::factory()->create();
        $program->participants()->attach($member, ['joined_at' => now()]);
        $candidate = User::factory()->create();

        $this->post(route('admin.programs.participants.store', $program), ['user_ids' => [$candidate->id]])->assertRedirect(route('login'));
        $this->delete(route('admin.programs.participants.destroy', [$program, $member]))->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->post(route('admin.programs.participants.store', $program), ['user_ids' => [$candidate->id]])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.programs.participants.destroy', [$program, $member]))->assertForbidden();
        }

        $this->assertSame([$member->id], $program->participants()->pluck('users.id')->all());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
