<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\MentorAssignment;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTrainingSessionMentorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_adds_two_coaches_who_are_listed_as_mentors_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $ana = User::factory()->create(['role' => 'coach', 'name' => 'Ana Reyes', 'employee_id' => 'EMP-0101']);
        $ben = User::factory()->create(['role' => 'coach', 'name' => 'Ben Lim', 'employee_id' => 'EMP-0102']);

        $this->actingAs($admin)
            ->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [(string) $ben->id, (string) $ana->id]])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session).'#mentors')
            ->assertSessionHas('status', 'Added 2 mentors.');

        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], $session->mentors()->pluck('users.id')->all());

        $audits = AdminAuditLog::query()->orderBy('target_user_id')->get();
        $this->assertSame([$ana->id, $ben->id], $audits->pluck('target_user_id')->all());
        foreach ($audits as $audit) {
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame('session_mentor_added', $audit->action);
            $this->assertSame(['training_session_id' => $session->id], $audit->metadata);
        }

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('Mentors (2)')
            ->assertSeeInOrder(['Ana Reyes', 'EMP-0101', 'Ben Lim', 'EMP-0102']);
    }

    public function test_participants_admins_and_inactive_coaches_cannot_be_added(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $users = [
            User::factory()->create(['role' => 'participant']),
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'coach', 'is_active' => false]),
        ];

        foreach ($users as $user) {
            $this->actingAs($admin)
                ->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [$user->id]])
                ->assertRedirect(route('admin.sessions.show', $session).'#mentors')
                ->assertSessionHasErrorsIn('mentors', ['user_ids.0' => 'Only active users with the Mentor role can be added as mentors.']);
        }

        $this->assertDatabaseCount('training_session_mentors', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_adding_a_coach_who_is_already_a_mentor_of_the_session_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->withMentors($mentor)->create();
        $newcomer = User::factory()->create(['role' => 'coach']);

        $this->actingAs($admin)
            ->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [$newcomer->id, $mentor->id]])
            ->assertSessionHasErrorsIn('mentors', ['user_ids.1' => 'The selected mentor is already a mentor of this session.']);

        $this->assertSame([$mentor->id], $session->mentors()->pluck('users.id')->all());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_adding_requires_at_least_one_mentor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.mentors.store', $session), ['user_ids' => []])
            ->assertSessionHasErrorsIn('mentors', ['user_ids' => 'Choose at least one mentor to add.']);

        $this->assertDatabaseCount('training_session_mentors', 0);
    }

    public function test_the_mentors_tab_offers_only_active_coaches_not_yet_mentors_and_counts_current_participants(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor', 'employee_id' => null]);
        $otherSessionMentor = User::factory()->create(['role' => 'coach', 'name' => 'Ava Coach', 'employee_id' => 'EMP-0001']);
        [$first, $second, $former] = User::factory()->count(3)->create();
        $session = TrainingSession::factory()->withParticipants($first, $second, $former)->withMentors($mentor)->create();
        $otherSession = TrainingSession::factory()->withParticipants($first)->withMentors($mentor, $otherSessionMentor)->create();
        MentorAssignment::factory()->for($session)->create(['participant_id' => $first->id, 'mentor_id' => $mentor->id]);
        MentorAssignment::factory()->for($session)->create(['participant_id' => $second->id, 'mentor_id' => $mentor->id]);
        MentorAssignment::factory()->for($session)->ended()->create(['participant_id' => $former->id, 'mentor_id' => $mentor->id]);
        MentorAssignment::factory()->for($otherSession)->create(['participant_id' => $first->id, 'mentor_id' => $mentor->id]);
        User::factory()->create(['role' => 'coach', 'name' => 'Cara Coach', 'employee_id' => null]);
        User::factory()->create(['role' => 'coach', 'name' => 'Ian Inactive', 'is_active' => false]);
        User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        User::factory()->create(['name' => 'Pat Participant']);

        $response = $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('Mentors (1)')
            ->assertSee('action="'.route('admin.sessions.mentors.store', $session).'"', false)
            ->assertSee('<label for="session-mentor-ids" class="text-sm font-extrabold">Name (Employee ID)</label>', false)
            ->assertSeeInOrder(['<option value="'.$otherSessionMentor->id.'"', 'Ava Coach (EMP-0001)', 'Cara Coach (no employee ID)'], false)
            ->assertSeeInOrder(['<tr data-mentor-row>', 'Mia Mentor', '—', '>2<'], false)
            ->assertSee('action="'.route('admin.sessions.mentors.destroy', [$session, $mentor]).'" data-confirm="Remove Mia Mentor as a mentor of this session? Their 2 participants will become unassigned."', false)
            ->assertSee('aria-label="Remove Mia Mentor"', false);

        $mentorPicker = Str::betweenFirst($response->getContent(), 'id="session-mentor-ids"', '</select>');
        foreach (['Mia Mentor', 'Ian Inactive', 'Ada Admin', 'Pat Participant'] as $name) {
            $this->assertStringNotContainsString($name, $mentorPicker);
        }
    }

    public function test_the_remove_confirmation_says_when_a_mentor_has_no_participants_and_the_add_form_gives_way_to_a_note(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor']);
        $session = TrainingSession::factory()->withMentors($mentor)->create();

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('data-confirm="Remove Mia Mentor as a mentor of this session? They have no participants assigned."', false)
            ->assertSee('There are no other active users with the Mentor role to add.')
            ->assertDontSee('action="'.route('admin.sessions.mentors.store', $session).'"', false);
    }

    public function test_removing_a_mentor_ends_their_assignments_in_the_session_and_is_audited(): void
    {
        $this->travelTo('2026-11-18 11:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $leaving = User::factory()->create(['role' => 'coach', 'name' => 'Lee Leaving']);
        $staying = User::factory()->create(['role' => 'coach']);
        [$first, $second, $third] = User::factory()->count(3)->create();
        $session = TrainingSession::factory()->withParticipants($first, $second, $third)->withMentors($leaving, $staying)->create();
        $otherSession = TrainingSession::factory()->withParticipants($first)->withMentors($leaving)->create();
        $endedFirst = MentorAssignment::factory()->for($session)->create(['participant_id' => $first->id, 'mentor_id' => $leaving->id, 'started_at' => '2026-11-09 09:00:00']);
        $endedSecond = MentorAssignment::factory()->for($session)->create(['participant_id' => $second->id, 'mentor_id' => $leaving->id, 'started_at' => '2026-11-09 09:00:00']);
        $kept = MentorAssignment::factory()->for($session)->create(['participant_id' => $third->id, 'mentor_id' => $staying->id]);
        $otherSessionAssignment = MentorAssignment::factory()->for($otherSession)->create(['participant_id' => $first->id, 'mentor_id' => $leaving->id]);

        $this->actingAs($admin)
            ->delete(route('admin.sessions.mentors.destroy', [$session, $leaving]))
            ->assertRedirect(route('admin.sessions.show', $session).'#mentors')
            ->assertSessionHas('status', 'Removed Lee Leaving as a mentor of the session.');

        $this->assertSame([$staying->id], $session->mentors()->pluck('users.id')->all());
        $this->assertSame([$leaving->id], $otherSession->mentors()->pluck('users.id')->all());
        $this->assertSame('2026-11-18 11:00:00', $endedFirst->fresh()->ended_at->toDateTimeString());
        $this->assertSame('2026-11-18 11:00:00', $endedSecond->fresh()->ended_at->toDateTimeString());
        $this->assertNull($kept->fresh()->ended_at);
        $this->assertNull($otherSessionAssignment->fresh()->ended_at);
        $this->assertSame(0, MentorAssignment::query()->where('training_session_id', $session->id)->whereIn('participant_id', [$first->id, $second->id])->current()->count());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($leaving->id, $audit->target_user_id);
        $this->assertSame('session_mentor_removed', $audit->action);
        $this->assertSame(['training_session_id' => $session->id], $audit->metadata);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('Mentors (1)')
            ->assertDontSee('aria-label="Remove Lee Leaving"', false);
    }

    public function test_removing_someone_who_is_not_a_mentor_of_the_session_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $outsider = User::factory()->create(['role' => 'coach']);
        TrainingSession::factory()->withMentors($outsider)->create();

        $this->actingAs($admin)
            ->delete(route('admin.sessions.mentors.destroy', [$session, $outsider]))
            ->assertNotFound();

        $this->assertDatabaseCount('training_session_mentors', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $mentor = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->withMentors($mentor)->create();
        $candidate = User::factory()->create(['role' => 'coach']);

        $this->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [$candidate->id]])->assertRedirect(route('login'));
        $this->delete(route('admin.sessions.mentors.destroy', [$session, $mentor]))->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [$candidate->id]])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.sessions.mentors.destroy', [$session, $mentor]))->assertForbidden();
        }

        $this->assertSame([$mentor->id], $session->mentors()->pluck('users.id')->all());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
