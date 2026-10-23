<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminTrainingSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_a_session_that_is_audited_and_listed(): void
    {
        $this->travelTo('2026-10-23 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4, ['name' => 'L4 Accelerator Training']);

        $this->actingAs($admin)
            ->post(route('admin.sessions.store'), $this->sessionPayload($program))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.index'));

        $session = TrainingSession::query()->sole();
        $this->assertSame($program->id, $session->program_id);
        $this->assertSame('L4 Accelerator Training — November 2026', $session->title);
        $this->assertSame(4, $session->weeks_count);
        $this->assertSame('2026-11-09', $session->starts_on->toDateString());
        $this->assertSame('2026-12-06', $session->ends_on->toDateString());
        $this->assertSame($admin->id, $session->created_by);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame('training_session_created', $audit->action);
        $this->assertSame(['training_session_id' => $session->id], $audit->metadata);

        $this->actingAs($admin)
            ->get(route('admin.sessions.index'))
            ->assertOk()
            ->assertSeeInOrder(['L4 Accelerator Training — November 2026', 'L4 Accelerator Training', '9 Nov – 6 Dec 2026', 'Upcoming']);
    }

    public function test_the_create_form_offers_only_active_programs_with_their_number_of_active_weeks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4, ['name' => 'L4 Accelerator Training']);
        TrainingWeek::factory()->for($program)->create(['week_number' => 5, 'position' => 5, 'is_active' => false]);
        $this->programWithWeeks(2, ['name' => 'Retired Program', 'is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.sessions.create'))
            ->assertOk()
            ->assertSee('L4 Accelerator Training (4 weeks)')
            ->assertDontSee('Retired Program');
    }

    public function test_an_end_date_that_does_not_follow_from_the_start_date_and_weeks_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);

        $this->actingAs($admin)
            ->from(route('admin.sessions.create'))
            ->post(route('admin.sessions.store'), $this->sessionPayload($program, ['ends_on' => '2026-12-07']))
            ->assertRedirect(route('admin.sessions.create'))
            ->assertSessionHasErrors(['ends_on' => 'The end date must be 4 weeks after the start date (6 Dec 2026).']);

        $this->assertDatabaseCount('training_sessions', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_more_weeks_than_the_programs_active_weeks_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        TrainingWeek::factory()->for($program)->create(['week_number' => 5, 'position' => 5, 'is_active' => false]);

        $this->actingAs($admin)
            ->post(route('admin.sessions.store'), $this->sessionPayload($program, ['weeks_count' => 5, 'ends_on' => '2026-12-13']))
            ->assertSessionHasErrors(['weeks_count' => 'The program has only 4 weeks.']);

        $this->assertDatabaseCount('training_sessions', 0);
    }

    public function test_an_inactive_program_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4, ['is_active' => false]);

        $this->actingAs($admin)
            ->post(route('admin.sessions.store'), $this->sessionPayload($program))
            ->assertSessionHasErrors(['program_id' => 'The selected program is invalid.']);

        $this->assertDatabaseCount('training_sessions', 0);
    }

    public function test_a_session_overlapping_another_session_of_the_same_program_is_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $existing = TrainingSession::factory()->for($program)->create(['starts_on' => '2026-11-02', 'ends_on' => '2026-11-29']);

        $this->actingAs($admin)
            ->post(route('admin.sessions.store'), $this->sessionPayload($program))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.index'));

        $this->assertSame(2, $program->trainingSessions()->count());
        $this->assertModelExists($existing);
    }

    public function test_participants_and_coaches_are_forbidden_on_every_session_route(): void
    {
        $program = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('admin.sessions.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.sessions.create'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.sessions.store'), $this->sessionPayload($program))->assertForbidden();
            $this->actingAs($user)->get(route('admin.sessions.show', $session))->assertForbidden();
            $this->actingAs($user)->get(route('admin.sessions.edit', $session))->assertForbidden();
            $this->actingAs($user)->put(route('admin.sessions.update', $session), $this->sessionPayload($program, ['title' => 'Renamed']))->assertForbidden();
        }

        $this->assertDatabaseCount('training_sessions', 1);
        $this->assertSame('L4 Accelerator Training — November 2026', $session->fresh()->title);
    }

    public function test_guests_are_sent_to_login_from_every_session_route(): void
    {
        $program = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);

        $this->get(route('admin.sessions.index'))->assertRedirect(route('login'));
        $this->get(route('admin.sessions.create'))->assertRedirect(route('login'));
        $this->post(route('admin.sessions.store'), $this->sessionPayload($program))->assertRedirect(route('login'));
        $this->get(route('admin.sessions.show', $session))->assertRedirect(route('login'));
        $this->get(route('admin.sessions.edit', $session))->assertRedirect(route('login'));
        $this->put(route('admin.sessions.update', $session), $this->sessionPayload($program, ['title' => 'Renamed']))->assertRedirect(route('login'));

        $this->assertDatabaseCount('training_sessions', 1);
        $this->assertSame('L4 Accelerator Training — November 2026', $session->fresh()->title);
    }

    public function test_the_list_shows_twenty_sessions_per_page_newest_start_date_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        TrainingSession::factory()
            ->for($program)
            ->count(21)
            ->state(new Sequence(fn (Sequence $sequence): array => [
                'title' => sprintf('Session %02d', $sequence->index + 1),
                'starts_on' => Carbon::parse('2026-01-05')->addWeeks($sequence->index)->toDateString(),
                'ends_on' => Carbon::parse('2026-02-01')->addWeeks($sequence->index)->toDateString(),
            ]))
            ->create();
        $newestFirst = array_map(fn (int $number): string => sprintf('Session %02d', $number), range(21, 2));

        $this->actingAs($admin)
            ->get(route('admin.sessions.index'))
            ->assertOk()
            ->assertSeeInOrder($newestFirst)
            ->assertDontSee('Session 01');

        $this->actingAs($admin)
            ->get(route('admin.sessions.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Session 01')
            ->assertDontSee('Session 02');
    }

    public function test_each_row_shows_the_program_date_range_status_and_participant_count_and_links_to_the_session(): void
    {
        $this->travelTo('2027-01-10 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()
            ->for(Program::factory()->state(['name' => 'Backend Basics']))
            ->hasAttached(User::factory()->count(3), ['joined_at' => now()], 'participants')
            ->create(['title' => 'Winter Run', 'starts_on' => '2026-12-28', 'ends_on' => '2027-01-24']);

        $this->actingAs($admin)
            ->get(route('admin.sessions.index'))
            ->assertOk()
            ->assertSeeInOrder([$session->title, 'Backend Basics', '28 Dec 2026 – 24 Jan 2027', 'Active', '>3<', route('admin.sessions.edit', $session)], false)
            ->assertSee('<a href="'.route('admin.sessions.show', $session).'"', false);
    }

    public function test_admin_updates_a_sessions_title_weeks_and_dates_and_the_changed_fields_are_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);

        $this->actingAs($admin)
            ->get(route('admin.sessions.edit', $session))
            ->assertOk()
            ->assertSee('value="L4 Accelerator Training — November 2026"', false)
            ->assertSee('value="2026-11-09"', false)
            ->assertSee('value="2026-12-06"', false);

        $this->actingAs($admin)
            ->put(route('admin.sessions.update', $session), $this->sessionPayload($program, [
                'title' => 'L4 Accelerator Training — Late November 2026',
                'weeks_count' => 3,
                'starts_on' => '2026-11-16',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session));

        $session->refresh();
        $this->assertSame('L4 Accelerator Training — Late November 2026', $session->title);
        $this->assertSame(3, $session->weeks_count);
        $this->assertSame('2026-11-16', $session->starts_on->toDateString());
        $this->assertSame('2026-12-06', $session->ends_on->toDateString());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('training_session_updated', $audit->action);
        $this->assertSame([
            'training_session_id' => $session->id,
            'changed_fields' => ['title', 'weeks_count', 'starts_on'],
        ], $audit->metadata);
    }

    public function test_saving_unchanged_session_details_records_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);

        $this->actingAs($admin)
            ->put(route('admin.sessions.update', $session), $this->sessionPayload($program))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session));

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_an_update_with_an_end_date_that_does_not_follow_from_the_start_date_and_weeks_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);

        $this->actingAs($admin)
            ->from(route('admin.sessions.edit', $session))
            ->put(route('admin.sessions.update', $session), $this->sessionPayload($program, ['title' => 'Renamed', 'weeks_count' => 3]))
            ->assertRedirect(route('admin.sessions.edit', $session))
            ->assertSessionHasErrors(['ends_on' => 'The end date must be 3 weeks after the start date (29 Nov 2026).']);

        $session->refresh();
        $this->assertSame('L4 Accelerator Training — November 2026', $session->title);
        $this->assertSame(4, $session->weeks_count);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_program_cannot_change_once_a_participant_has_joined(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $otherProgram = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);
        $session->participants()->attach(User::factory()->create(), ['joined_at' => now()]);

        $this->actingAs($admin)
            ->put(route('admin.sessions.update', $session), $this->sessionPayload($otherProgram, ['title' => 'Renamed']))
            ->assertSessionHasErrors(['program_id' => 'The program cannot change after participants have joined.']);

        $session->refresh();
        $this->assertSame($program->id, $session->program_id);
        $this->assertSame('L4 Accelerator Training — November 2026', $session->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_program_can_change_while_no_participant_has_joined(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $otherProgram = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);

        $this->actingAs($admin)
            ->put(route('admin.sessions.update', $session), $this->sessionPayload($otherProgram))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session));

        $this->assertSame($otherProgram->id, $session->fresh()->program_id);
        $this->assertSame(['training_session_id' => $session->id, 'changed_fields' => ['program_id']], AdminAuditLog::query()->sole()->metadata);
    }

    public function test_the_edit_form_locks_the_program_once_a_participant_has_joined_and_still_saves(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->programWithWeeks(4);
        $session = $this->sessionOf($program);
        $session->participants()->attach(User::factory()->create(), ['joined_at' => now()]);

        $this->actingAs($admin)
            ->get(route('admin.sessions.edit', $session))
            ->assertOk()
            ->assertSee('<input type="hidden" name="program_id" value="'.$program->id.'">', false)
            ->assertSee('<select id="program_id" disabled', false)
            ->assertSee('The program cannot change after participants have joined.')
            ->assertSee('<a href="'.route('admin.sessions.show', $session).'"', false);

        $this->actingAs($admin)
            ->put(route('admin.sessions.update', $session), $this->sessionPayload($program, ['title' => 'Renamed']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session));

        $this->assertSame('Renamed', $session->fresh()->title);
    }

    /**
     * An active program with active weeks 1 to N.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function programWithWeeks(int $weeksCount, array $attributes = []): Program
    {
        $program = Program::factory()->create($attributes);
        TrainingWeek::factory()
            ->for($program)
            ->count($weeksCount)
            ->state(new Sequence(fn (Sequence $sequence): array => ['week_number' => $sequence->index + 1, 'position' => $sequence->index + 1]))
            ->create();

        return $program;
    }

    /**
     * A saved 4-week session of the program with the same details as sessionPayload().
     */
    private function sessionOf(Program $program): TrainingSession
    {
        return TrainingSession::factory()->for($program)->create([
            'title' => 'L4 Accelerator Training — November 2026',
            'weeks_count' => 4,
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-12-06',
        ]);
    }

    /**
     * The create form for a 4-week session of the program from Mon 9 Nov to Sun 6 Dec 2026.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sessionPayload(Program $program, array $overrides = []): array
    {
        return [
            'program_id' => $program->id,
            'title' => 'L4 Accelerator Training — November 2026',
            'weeks_count' => 4,
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-12-06',
            ...$overrides,
        ];
    }
}
