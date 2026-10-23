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

        $participant = User::factory()->create();
        $session->participants()->attach($participant, ['joined_at' => now()]);
        $assignUrl = route('admin.sessions.assignments.update', [$session, $participant]);

        $this->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [$candidate->id]])->assertRedirect(route('login'));
        $this->delete(route('admin.sessions.mentors.destroy', [$session, $mentor]))->assertRedirect(route('login'));
        $this->put($assignUrl, ['mentor_id' => $mentor->id])->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->post(route('admin.sessions.mentors.store', $session), ['user_ids' => [$candidate->id]])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.sessions.mentors.destroy', [$session, $mentor]))->assertForbidden();
            $this->actingAs($user)->put($assignUrl, ['mentor_id' => $mentor->id])->assertForbidden();
        }

        $this->assertSame([$mentor->id], $session->mentors()->pluck('users.id')->all());
        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_admin_assigns_a_mentor_then_changes_it_and_the_tabs_show_the_current_mentor_and_the_history(): void
    {
        $this->travelTo('2026-11-09 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $ana = User::factory()->create(['role' => 'coach', 'name' => 'Ana Reyes']);
        $ben = User::factory()->create(['role' => 'coach', 'name' => 'Ben Lim']);
        $participant = User::factory()->create(['name' => 'Pat Cruz']);
        $session = TrainingSession::factory()->withParticipants($participant)->withMentors($ana, $ben)->create();
        $assignUrl = route('admin.sessions.assignments.update', [$session, $participant]);

        $this->actingAs($admin)
            ->put($assignUrl, ['mentor_id' => (string) $ana->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session).'#mentors')
            ->assertSessionHas('status', 'Ana Reyes now mentors Pat Cruz.');

        $this->travelTo('2026-11-23 10:00:00');
        $this->actingAs($admin)
            ->put($assignUrl, ['mentor_id' => (string) $ben->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ben Lim now mentors Pat Cruz.');

        $assignments = MentorAssignment::query()->orderBy('id')->get();
        $this->assertSame([$ana->id, $ben->id], $assignments->pluck('mentor_id')->all());
        $this->assertSame('2026-11-23 10:00:00', $assignments[0]->ended_at->toDateTimeString());
        $this->assertNull($assignments[1]->ended_at);
        $this->assertSame([$admin->id, $admin->id], $assignments->pluck('assigned_by')->all());

        $audits = AdminAuditLog::query()->orderBy('id')->get();
        $this->assertSame(['session_mentor_assigned', 'session_mentor_assigned'], $audits->pluck('action')->all());
        $this->assertSame([$admin->id, $admin->id], $audits->pluck('actor_id')->all());
        $this->assertSame([$participant->id, $participant->id], $audits->pluck('target_user_id')->all());
        $this->assertSame(['training_session_id' => $session->id, 'previous_mentor_id' => null, 'mentor_id' => $ana->id], $audits[0]->metadata);
        $this->assertSame(['training_session_id' => $session->id, 'previous_mentor_id' => $ana->id, 'mentor_id' => $ben->id], $audits[1]->metadata);

        $response = $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['<tr data-participant-row>', 'Pat Cruz', '<td class="', 'Ben Lim</td>'], false)
            ->assertSee('<form method="POST" action="'.$assignUrl.'"', false)
            ->assertSee('<label for="session-mentor-for-'.$participant->id.'" class="sr-only">Mentor for Pat Cruz</label>', false)
            ->assertSee('<option value="'.$ben->id.'" selected>Ben Lim</option>', false)
            ->assertSee('<option value="'.$ana->id.'" >Ana Reyes</option>', false)
            ->assertDontSee('>Not assigned<', false)
            ->assertSeeInOrder(['Show history (2)', '<li>Ana Reyes, 9 Nov – 23 Nov</li>', '<li>Ben Lim, since 23 Nov</li>'], false);

        $participantsTab = Str::betweenFirst($response->getContent(), 'id="session-panel-participants"', 'id="session-panel-mentors"');
        $this->assertStringNotContainsString('Ana Reyes', $participantsTab);
    }

    public function test_a_participant_without_a_mentor_shows_as_not_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor']);
        $participant = User::factory()->create(['name' => 'Pat Cruz']);
        $session = TrainingSession::factory()->withParticipants($participant)->withMentors($mentor)->create();

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['<tr data-participant-row>', 'Pat Cruz', 'Not assigned</td>'], false)
            ->assertSeeInOrder(['<tr data-assignment-row>', '<option value="" selected>Not assigned</option>', '<option value="'.$mentor->id.'" >Mia Mentor</option>'], false)
            ->assertSee('No mentor yet');
    }

    public function test_the_mentors_tab_explains_when_there_are_no_participants_or_no_mentors_to_assign(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $withoutParticipants = TrainingSession::factory()->withMentors(User::factory()->create(['role' => 'coach']))->create();
        $withoutMentors = TrainingSession::factory()->withParticipants(User::factory()->create())->create();

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $withoutParticipants))
            ->assertOk()
            ->assertSee('Add participants to the session to assign their mentors.')
            ->assertDontSee('<tr data-assignment-row>', false);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $withoutMentors))
            ->assertOk()
            ->assertSee('Add mentors to the session to assign them to participants.')
            ->assertSee('<tr data-assignment-row>', false)
            ->assertDontSee('name="mentor_id"', false);
    }

    public function test_choosing_the_current_mentor_again_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mia Mentor']);
        $participant = User::factory()->create(['name' => 'Pat Cruz']);
        $session = TrainingSession::factory()->withParticipants($participant)->withMentors($mentor)->create();
        $assignment = MentorAssignment::factory()->for($session)->create(['participant_id' => $participant->id, 'mentor_id' => $mentor->id]);

        $this->actingAs($admin)
            ->put(route('admin.sessions.assignments.update', [$session, $participant]), ['mentor_id' => $mentor->id])
            ->assertRedirect(route('admin.sessions.show', $session).'#mentors')
            ->assertSessionHas('status', 'Mia Mentor already mentors Pat Cruz.');

        $this->assertSame([$assignment->id], MentorAssignment::query()->pluck('id')->all());
        $this->assertNull($assignment->fresh()->ended_at);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_choosing_a_coach_who_is_not_a_mentor_of_the_session_or_no_mentor_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach']);
        $otherSessionMentor = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pat Cruz']);
        $other = User::factory()->create();
        $session = TrainingSession::factory()->withParticipants($participant, $other)->withMentors($mentor)->create();
        TrainingSession::factory()->withParticipants($participant)->withMentors($otherSessionMentor)->create();
        $assignUrl = route('admin.sessions.assignments.update', [$session, $participant]);
        $errorBag = 'assignMentor'.$participant->id;

        $this->actingAs($admin)
            ->put($assignUrl, ['mentor_id' => $otherSessionMentor->id])
            ->assertRedirect(route('admin.sessions.show', $session).'#mentors')
            ->assertSessionHasErrorsIn($errorBag, ['mentor_id' => 'The selected mentor is not a mentor of this training session.']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($assignUrl, ['mentor_id' => $otherSessionMentor->id])
            ->assertOk()
            ->assertSee('aria-invalid="true" aria-describedby="session-mentor-for-'.$participant->id.'-error"', false)
            ->assertSee('<p id="session-mentor-for-'.$participant->id.'-error" class="text-sm text-rose-200 sm:col-span-2" role="alert">The selected mentor is not a mentor of this training session.</p>', false)
            ->assertDontSee('id="session-mentor-for-'.$other->id.'-error"', false);

        $this->actingAs($admin)
            ->put($assignUrl, ['mentor_id' => ''])
            ->assertSessionHasErrorsIn($errorBag, ['mentor_id' => 'Choose a mentor.']);

        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_assigning_a_mentor_to_someone_who_is_not_in_the_session_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach']);
        $outsider = User::factory()->create();
        $session = TrainingSession::factory()->withMentors($mentor)->create();
        TrainingSession::factory()->withParticipants($outsider)->create();

        $this->actingAs($admin)
            ->put(route('admin.sessions.assignments.update', [$session, $outsider]), ['mentor_id' => $mentor->id])
            ->assertNotFound();

        $this->assertDatabaseCount('mentor_assignments', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
