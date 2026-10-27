<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\CurriculumOrderService;
use App\Services\GateReadinessService;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumActivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_activity_page_shows_its_details_submission_fields_and_scoring(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2, 'title' => 'Testing']);
        $activity = Activity::factory()->for($week, 'week')->withScoringDimensions()->create([
            'title' => 'Unit testing basics',
            'description' => 'Write your first tests.',
            'objectives' => 'Cover the happy path.',
            'instructions' => 'Write a test. Make it pass.',
        ]);
        $this->field($activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => 'Anything your mentor should know.'], 2);
        $this->field($activity, ['key' => 'pull_request', 'label' => 'Pull request link', 'type' => 'github_url', 'required' => true, 'help' => null], 1);
        $this->field($activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);
        ActivityRequirement::factory()->for($activity)->create(['title' => 'Legacy deliverable requirement']);
        TrainingSession::factory()->for($program)->active()->create(['title' => 'Live Run']);

        $this->actingAs($admin)
            ->get(route('admin.programs.activities.edit', [$program, $week, $activity]))
            ->assertOk()
            ->assertSee('<title>Unit testing basics</title>', false)
            ->assertSeeInOrder(['ADMIN · CURRICULUM', 'Unit testing basics', 'Week 2: Testing', 'L4 Accelerator Training', 'Active'])
            ->assertSee('<a href="'.route('admin.programs.weeks.show', [$program, $week]).'#activities"', false)
            ->assertSee('data-sessions-notice="warning"', false)
            ->assertSee('Live Run')
            ->assertSeeInOrder(['Details', 'Submission fields', 'Scoring'])
            ->assertSeeInOrder([
                'action="'.route('admin.programs.activities.update', [$program, $week, $activity]).'"',
                'name="_method" value="PUT"',
                'value="Unit testing basics"',
                '>Write your first tests.</textarea>',
                '>Cover the happy path.</textarea>',
                '>Write a test. Make it pass.</textarea>',
                '>Save Details<',
            ], false)
            ->assertSeeInOrder([
                'Pull request link', 'Github Url', 'Required', 'pull_request',
                'Notes', 'Textarea', 'Optional', 'Anything your mentor should know.', 'notes',
                'Removed fields', 'Retired link', 'old_link',
                'D1', 'Deliverable', '40', 'D4', 'Reflection', '10', 'Total', '100',
            ])
            ->assertDontSee('Legacy deliverable requirement');
    }

    public function test_the_week_page_links_each_activity_to_its_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $editUrl = route('admin.programs.activities.edit', [$activity->week->program_id, $activity->week, $activity]);

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$activity->week->program_id, $activity->week]))
            ->assertOk()
            ->assertSee('<a href="'.$editUrl.'"', false)
            ->assertSee('<a href="'.$editUrl.'" aria-label="Edit Unit testing basics"', false);
    }

    public function test_admin_saves_the_activitys_text_and_it_is_audited_while_its_configuration_survives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $configuration = $activity->configuration;

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), [
                'title' => 'Testing in depth',
                'description' => 'Write your first tests.',
                'objectives' => 'Cover the failure paths too.',
                'instructions' => "Write a failing test.\r\nMake it pass.",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($activity).'#details')
            ->assertSessionHas('status', 'Saved "Testing in depth".');

        $activity->refresh();
        $this->assertSame('Testing in depth', $activity->title);
        $this->assertSame('Write your first tests.', $activity->description);
        $this->assertSame('Cover the failure paths too.', $activity->objectives);
        $this->assertSame("Write a failing test.\nMake it pass.", $activity->instructions);
        $this->assertSame($configuration, $activity->configuration);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame('curriculum_activity_updated', $audit->action);
        $this->assertSame([
            'program_id' => $activity->week->program_id,
            'training_week_id' => $activity->training_week_id,
            'activity_id' => $activity->id,
            'changes' => [
                'title' => ['from' => 'Unit testing basics', 'to' => 'Testing in depth'],
                'objectives' => ['from' => 'Cover the happy path.', 'to' => 'Cover the failure paths too.'],
                'instructions' => ['from' => 'Write a test. Make it pass.', 'to' => "Write a failing test.\nMake it pass."],
            ],
        ], $audit->metadata);
    }

    public function test_participants_see_the_new_objectives_and_instructions_on_the_activity_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        $activity = $this->activity();
        $session = TrainingSession::factory()->forWeek($activity->week)->withParticipants($participant)->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), [
                'title' => 'Unit testing basics',
                'description' => 'Write your first tests.',
                'objectives' => 'Cover the failure paths too.',
                'instructions' => 'Write a failing test first.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->get(route('training.activities.show', [$session, $activity->week, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['Objective', 'Cover the failure paths too.', 'Instructions', 'Write a failing test first.'])
            ->assertDontSee('Cover the happy path.')
            ->assertDontSee('Write a test. Make it pass.');
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidDetails')]
    public function test_invalid_details_fail_in_the_activity_bag_and_save_nothing(array $payload, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), $payload)
            ->assertRedirect($this->editUrl($activity).'#details')
            ->assertSessionHasErrorsIn('activity', [$field => $message]);

        $activity->refresh();
        $this->assertSame('Unit testing basics', $activity->title);
        $this->assertSame('Cover the happy path.', $activity->objectives);
        $this->assertSame('Write a test. Make it pass.', $activity->instructions);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidDetails(): array
    {
        return [
            'blank title' => [['title' => '', 'objectives' => 'New objective.'], 'title', 'The title field is required.'],
            'title over 255 characters' => [['title' => str_repeat('a', 256)], 'title', 'The title field must not be greater than 255 characters.'],
            'description over 5000 characters' => [['title' => 'Unit testing basics', 'description' => str_repeat('a', 5001)], 'description', 'The description field must not be greater than 5000 characters.'],
            'objectives over 5000 characters' => [['title' => 'Unit testing basics', 'objectives' => str_repeat('a', 5001)], 'objectives', 'The objectives field must not be greater than 5000 characters.'],
            'instructions over 20000 characters' => [['title' => 'Unit testing basics', 'instructions' => str_repeat('a', 20001)], 'instructions', 'The instructions field must not be greater than 20000 characters.'],
        ];
    }

    public function test_a_blank_title_shows_its_error_under_the_field_and_keeps_the_typed_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($this->updateUrl($activity), [
                'title' => '',
                'description' => 'Typed description.',
                'objectives' => 'Typed objective.',
                'instructions' => 'Typed instructions.',
            ])
            ->assertOk()
            ->assertSeeInOrder([
                'id="activity-title"',
                'value=""',
                'aria-invalid="true" aria-describedby="activity-title-error"',
                '<p id="activity-title-error" class="text-sm text-rose-200" role="alert">The title field is required.</p>',
                '>Typed description.</textarea>',
                '>Typed objective.</textarea>',
                '>Typed instructions.</textarea>',
            ], false)
            ->assertDontSee('role="status"', false);
    }

    public function test_an_unchanged_save_with_browser_line_breaks_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity(['instructions' => "Write a test.\nMake it pass.\nRefactor."]);
        $updatedAt = $activity->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), [
                'title' => 'Unit testing basics',
                'description' => 'Write your first tests.',
                'objectives' => 'Cover the happy path.',
                'instructions' => "Write a test.\r\nMake it pass.\rRefactor.",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($activity).'#details');

        $activity->refresh();
        $this->assertEquals($updatedAt, $activity->updated_at);
        $this->assertSame("Write a test.\nMake it pass.\nRefactor.", $activity->instructions);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_extra_input_cannot_change_the_activitys_position_status_week_or_configuration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $configuration = $activity->configuration;
        $otherWeek = TrainingWeek::factory()->for($activity->week->program)->create(['week_number' => 2, 'position' => 2]);

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), [
                'title' => 'Testing in depth',
                'position' => 9,
                'is_active' => '0',
                'requires_coach_review' => '0',
                'training_week_id' => $otherWeek->id,
                'configuration' => ['scoring_dimensions' => []],
            ])
            ->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertSame('Testing in depth', $activity->title);
        $this->assertSame(1, $activity->position);
        $this->assertTrue($activity->is_active);
        $this->assertTrue($activity->requires_coach_review);
        $this->assertNotSame($otherWeek->id, $activity->training_week_id);
        $this->assertSame($configuration, $activity->configuration);
        $this->assertSame(['title'], array_keys(AdminAuditLog::query()->sole()->metadata['changes']));
    }

    public function test_an_activity_of_another_week_or_a_week_of_another_program_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $week = $activity->week;
        $otherWeek = TrainingWeek::factory()->for($week->program)->create(['week_number' => 2, 'position' => 2]);
        $otherProgram = Program::factory()->create();
        $mismatches = [
            'activity of another week' => [$week->program_id, $otherWeek, $activity],
            'week of another program' => [$otherProgram, $week, $activity],
        ];

        foreach ($mismatches as $parameters) {
            $this->actingAs($admin)->get(route('admin.programs.activities.edit', $parameters))->assertNotFound();
            $this->actingAs($admin)->put(route('admin.programs.activities.update', $parameters), ['title' => 'Testing in depth'])->assertNotFound();
        }

        $this->assertSame('Unit testing basics', $activity->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $activity = $this->activity();

        $this->get($this->editUrl($activity))->assertRedirect(route('login'));
        $this->put($this->updateUrl($activity), ['title' => 'Testing in depth'])->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get($this->editUrl($activity))->assertForbidden();
            $this->actingAs($user)->put($this->updateUrl($activity), ['title' => 'Testing in depth'])->assertForbidden();
        }

        $this->assertSame('Unit testing basics', $activity->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_admin_adds_an_activity_after_the_inactive_ones_with_the_seeded_defaults_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seed(L4TrainingProgramSeeder::class);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->sole()
            ->weeks()->where('week_number', 1)->sole();
        $lastActivity = $week->activities()->orderByDesc('position')->first();
        $lastActivity->update(['is_active' => false]);
        $seededNotesField = $lastActivity->requirements()->where('configuration->key', 'notes')->sole();

        $response = $this->actingAs($admin)
            ->post($this->storeUrl($week), [
                'title' => 'Pair programming',
                'description' => 'Work with a partner.',
                'objectives' => 'Practise driving and navigating.',
                'instructions' => "Swap roles every 15 minutes.\r\nWrite down what you learned.",
            ]);

        $activity = $week->activities()->latest('id')->first();
        $response->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($activity))
            ->assertSessionHas('status', 'Added "Pair programming".');
        $this->assertSame($lastActivity->position + 1, $activity->position);
        $this->assertSame('Pair programming', $activity->title);
        $this->assertSame('Work with a partner.', $activity->description);
        $this->assertSame('Practise driving and navigating.', $activity->objectives);
        $this->assertSame("Swap roles every 15 minutes.\nWrite down what you learned.", $activity->instructions);
        $this->assertTrue($activity->is_active);
        $this->assertTrue($activity->requires_coach_review);
        $this->assertSame(['scoring_dimensions' => Activity::DEFAULT_SCORING_DIMENSIONS], $activity->configuration);

        $notesField = $activity->requirements()->sole();
        $this->assertSame(
            $seededNotesField->only(['requirement_type', 'title', 'description', 'is_required', 'configuration']),
            $notesField->only(['requirement_type', 'title', 'description', 'is_required', 'configuration']),
        );
        $this->assertSame(1, $notesField->position);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame('curriculum_activity_added', $audit->action);
        $this->assertSame([
            'program_id' => $week->program_id,
            'training_week_id' => $week->id,
            'activity_id' => $activity->id,
            'title' => 'Pair programming',
            'position' => $activity->position,
        ], $audit->metadata);
    }

    public function test_a_new_activity_shows_on_the_week_page_of_every_session_of_the_program(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $week = $activity->week;
        $participants = User::factory()->count(2)->create(['role' => 'participant']);
        $sessions = $participants->map(fn (User $participant): TrainingSession => TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create());

        $this->actingAs($admin)
            ->post($this->storeUrl($week), ['title' => 'Pair programming', 'description' => 'Work with a partner.'])
            ->assertSessionHasNoErrors();

        foreach ($participants as $index => $participant) {
            $this->actingAs($participant)
                ->get(route('training.weeks.show', [$sessions[$index], $week]))
                ->assertOk()
                ->assertSeeInOrder(['Unit testing basics', 'Pair programming', 'Work with a partner.']);
        }
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidDetails')]
    public function test_an_invalid_add_fails_in_the_add_activity_bag_and_creates_nothing(array $payload, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->activity()->week;

        $this->actingAs($admin)
            ->post($this->storeUrl($week), $payload)
            ->assertRedirect(route('admin.programs.weeks.show', [$week->program_id, $week]).'#activities')
            ->assertSessionHasErrorsIn('addActivity', [$field => $message]);

        $this->assertSame(1, $week->activities()->count());
        $this->assertDatabaseCount('activity_requirements', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_failed_add_shows_its_errors_on_the_activities_tab_and_keeps_the_typed_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->activity()->week;
        $week->update(['title' => 'Foundations']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->post($this->storeUrl($week), [
                'title' => '',
                'description' => 'Typed description.',
                'objectives' => 'Typed objective.',
                'instructions' => 'Typed instructions.',
            ])
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-title"',
                'value="Foundations"',
                'Add activity',
                'action="'.$this->storeUrl($week).'"',
                'id="add-activity-title"',
                'value=""',
                'aria-invalid="true" aria-describedby="add-activity-title-error"',
                '<p id="add-activity-title-error" class="text-sm text-rose-200" role="alert">The title field is required.</p>',
                '>Typed description.</textarea>',
                '>Typed objective.</textarea>',
                '>Typed instructions.</textarea>',
                '>Add Activity<',
            ], false)
            ->assertDontSee('id="week-title-error"', false);
    }

    public function test_an_activity_cannot_be_added_to_a_week_of_another_program(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->activity()->week;
        $otherProgram = Program::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.programs.activities.store', [$otherProgram, $week]), ['title' => 'Pair programming'])
            ->assertNotFound();

        $this->assertSame(1, $week->activities()->count());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_only_admins_can_add_an_activity(): void
    {
        $week = $this->activity()->week;

        $this->post($this->storeUrl($week), ['title' => 'Pair programming'])->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post($this->storeUrl($week), ['title' => 'Pair programming'])->assertForbidden();
        }

        $this->assertSame(1, $week->activities()->count());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_week_page_shows_each_activitys_field_count_and_row_buttons(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle, $last] = $this->threeActivities();
        $middle->update(['is_active' => false]);
        $this->field($first, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 1);
        $this->field($first, ['key' => 'pull_request', 'label' => 'Pull request link', 'type' => 'github_url', 'required' => true, 'help' => null], 2);
        $this->field($first, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);
        ActivityRequirement::factory()->for($first)->create(['title' => 'Legacy deliverable requirement']);

        $this->actingAs($admin)
            ->get($this->weekUrl($first->week))
            ->assertOk()
            ->assertViewHas('activities', fn (array $activities): bool => array_column($activities, 'field_count') === [2, 0, 0]
                && array_column($activities, 'can_move_up') === [false, true, true]
                && array_column($activities, 'can_move_down') === [true, true, false])
            ->assertSeeInOrder([
                'Unit testing basics',
                'action="'.$this->moveUrl($first).'"',
                'name="direction" value="up" aria-label="Move Unit testing basics up"',
                'name="direction" value="down" aria-label="Move Unit testing basics down"',
                'action="'.$this->deactivateUrl($first).'" data-confirm="Deactivate Unit testing basics? Participants keep their submissions, but the activity leaves the week for every session."',
                'aria-label="Deactivate Unit testing basics"',
                'Mocking drill',
                'action="'.$this->reactivateUrl($middle).'"',
                'aria-label="Reactivate Mocking drill"',
                'Refactor kata',
                'name="direction" value="down" aria-label="Move Refactor kata down"',
            ], false)
            ->assertSee('<button type="submit" disabled name="direction" value="up" aria-label="Move Unit testing basics up"', false)
            ->assertSee('<button type="submit" disabled name="direction" value="down" aria-label="Move Refactor kata down"', false)
            ->assertDontSee('disabled name="direction" value="up" aria-label="Move Mocking drill up"', false)
            ->assertDontSee('disabled name="direction" value="down" aria-label="Move Mocking drill down"', false)
            ->assertDontSee('action="'.$this->deactivateUrl($middle).'"', false)
            ->assertDontSee('action="'.$this->reactivateUrl($first).'"', false);
    }

    public function test_admin_moves_an_activity_up_and_down_and_each_move_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle, $last] = $this->threeActivities();

        $this->actingAs($admin)
            ->patch($this->moveUrl($middle), ['direction' => 'up'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($middle->week).'#activities')
            ->assertSessionHas('status', 'Moved "Mocking drill" up.');

        $this->assertPositions([$middle, $first, $last]);

        $this->actingAs($admin)
            ->patch($this->moveUrl($middle), ['direction' => 'down'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Moved "Mocking drill" down.');

        $this->assertPositions([$first, $middle, $last]);
        $audits = AdminAuditLog::query()->orderBy('id')->get();
        $this->assertSame(['curriculum_activity_moved', 'curriculum_activity_moved'], $audits->pluck('action')->all());
        $this->assertSame($admin->id, $audits[0]->actor_id);
        $this->assertSame([
            'program_id' => $middle->week->program_id,
            'training_week_id' => $middle->training_week_id,
            'activity_id' => $middle->id,
            'direction' => 'up',
            'changes' => ['position' => ['from' => 2, 'to' => 1]],
        ], $audits[0]->metadata);
        $this->assertSame(['direction' => 'down', 'changes' => ['position' => ['from' => 1, 'to' => 2]]], array_intersect_key($audits[1]->metadata, ['direction' => true, 'changes' => true]));
    }

    public function test_moving_the_first_activity_up_or_the_last_down_changes_nothing_and_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle, $last] = $this->threeActivities();

        $this->actingAs($admin)
            ->patch($this->moveUrl($first), ['direction' => 'up'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($first->week).'#activities')
            ->assertSessionMissing('status');
        $this->actingAs($admin)
            ->patch($this->moveUrl($last), ['direction' => 'down'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('status');

        $this->assertPositions([$first, $middle, $last]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_move_needs_a_direction_of_up_or_down(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle, $last] = $this->threeActivities();

        foreach ([[], ['direction' => 'sideways']] as $payload) {
            $this->actingAs($admin)
                ->patch($this->moveUrl($middle), $payload)
                ->assertSessionHasErrors('direction');
        }

        $this->assertPositions([$first, $middle, $last]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_order_service_swaps_two_positions_of_three_without_breaking_the_unique_index(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $first = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $second = Activity::factory()->for($week, 'week')->create(['position' => 5]);
        $third = Activity::factory()->for($week, 'week')->create(['position' => 9]);
        $otherWeekActivity = Activity::factory()->create(['position' => 10]);
        $siblings = fn (): mixed => Activity::query()->where('training_week_id', $week->id);
        $order = app(CurriculumOrderService::class);

        $this->assertTrue($order->move($first, $siblings(), 'down'));
        $this->assertSame([5, 1, 9], [$first->refresh()->position, $second->refresh()->position, $third->refresh()->position]);

        $this->assertTrue($order->move($third, $siblings(), 'up'));
        $this->assertSame([9, 1, 5], [$first->refresh()->position, $second->refresh()->position, $third->refresh()->position]);

        $this->assertFalse($order->move($first, $siblings(), 'down'));
        $this->assertFalse($order->move($second, $siblings(), 'up'));
        $this->assertSame([9, 1, 5], [$first->refresh()->position, $second->refresh()->position, $third->refresh()->position]);
        $this->assertSame(10, $otherWeekActivity->refresh()->position);
    }

    public function test_a_deactivated_activity_leaves_the_participant_week_page_and_readiness_and_keeps_its_submissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        [$first, $middle, $last] = $this->threeActivities();
        $week = $first->week;
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->handIn($session, $participant, $first);
        $this->handIn($session, $participant, $last);
        $middleSubmission = ActivitySubmission::factory()->for($middle)->for($session)->for($participant, 'participant')->create();
        $readiness = app(GateReadinessService::class);
        $this->assertSame(['activity_2'], array_keys($readiness->readiness($session, $participant, $gate)));

        $this->actingAs($admin)
            ->patch($this->deactivateUrl($middle))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($week).'#activities')
            ->assertSessionHas('status', 'Deactivated "Mocking drill".');

        $this->assertFalse($middle->refresh()->is_active);
        $this->assertSame(2, $middle->position);
        $this->assertModelExists($middleSubmission);
        $this->assertSame([], $readiness->readiness($session, $participant, $gate));
        $this->actingAs($participant)
            ->get(route('training.weeks.show', [$session, $week]))
            ->assertOk()
            ->assertSeeInOrder(['Unit testing basics', 'Refactor kata'])
            ->assertDontSee('Mocking drill');

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_activity_deactivated', $audit->action);
        $this->assertSame([
            'program_id' => $week->program_id,
            'training_week_id' => $week->id,
            'activity_id' => $middle->id,
            'changes' => ['is_active' => ['from' => true, 'to' => false]],
        ], $audit->metadata);
    }

    public function test_a_reactivated_activity_returns_at_its_old_position_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        [$first, $middle, $last] = $this->threeActivities();
        $middle->update(['is_active' => false]);
        $session = TrainingSession::factory()->forWeek($middle->week)->withParticipants($participant)->create();

        $this->actingAs($admin)
            ->patch($this->reactivateUrl($middle))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($middle->week).'#activities')
            ->assertSessionHas('status', 'Reactivated "Mocking drill".');

        $this->assertTrue($middle->refresh()->is_active);
        $this->assertPositions([$first, $middle, $last]);
        $this->actingAs($participant)
            ->get(route('training.weeks.show', [$session, $middle->week]))
            ->assertOk()
            ->assertSeeInOrder(['Unit testing basics', 'Mocking drill', 'Refactor kata']);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_activity_reactivated', $audit->action);
        $this->assertSame([
            'program_id' => $middle->week->program_id,
            'training_week_id' => $middle->training_week_id,
            'activity_id' => $middle->id,
            'changes' => ['is_active' => ['from' => false, 'to' => true]],
        ], $audit->metadata);
    }

    public function test_deactivating_an_inactive_or_reactivating_an_active_activity_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle] = $this->threeActivities();
        $middle->update(['is_active' => false]);

        $this->actingAs($admin)->patch($this->deactivateUrl($middle))->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch($this->reactivateUrl($first))->assertSessionHasNoErrors();

        $this->assertFalse($middle->refresh()->is_active);
        $this->assertTrue($first->refresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_weeks_last_active_activity_cannot_be_deactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle, $last] = $this->threeActivities();
        $first->update(['is_active' => false]);
        $last->update(['is_active' => false]);

        $this->actingAs($admin)
            ->patch($this->deactivateUrl($middle))
            ->assertRedirect($this->weekUrl($middle->week).'#activities')
            ->assertSessionHasErrorsIn('activities', ['activity' => 'A week needs at least one active activity.']);

        $this->assertTrue($middle->refresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->actingAs($admin)
            ->followingRedirects()
            ->patch($this->deactivateUrl($middle))
            ->assertOk()
            ->assertSee('role="alert" data-activities-error', false)
            ->assertSee('A week needs at least one active activity.')
            ->assertDontSee('role="status"', false);
    }

    public function test_an_activity_of_another_week_cannot_be_moved_deactivated_or_reactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $middle] = $this->threeActivities();
        $otherWeek = TrainingWeek::factory()->for($first->week->program)->create(['week_number' => 2, 'position' => 2]);
        $otherProgram = Program::factory()->create();
        $mismatches = [
            'activity of another week' => [$first->week->program_id, $otherWeek, $middle],
            'week of another program' => [$otherProgram, $first->week, $middle],
        ];

        foreach ($mismatches as $parameters) {
            $this->actingAs($admin)->patch(route('admin.programs.activities.move', $parameters), ['direction' => 'up'])->assertNotFound();
            $this->actingAs($admin)->patch(route('admin.programs.activities.deactivate', $parameters))->assertNotFound();
            $this->actingAs($admin)->patch(route('admin.programs.activities.reactivate', $parameters))->assertNotFound();
        }

        $this->assertSame(2, $middle->refresh()->position);
        $this->assertTrue($middle->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_only_admins_can_move_deactivate_or_reactivate_an_activity(): void
    {
        [$first, $middle] = $this->threeActivities();
        $middle->update(['is_active' => false]);
        $requests = [
            [$this->moveUrl($first), ['direction' => 'down']],
            [$this->deactivateUrl($first), []],
            [$this->reactivateUrl($middle), []],
        ];

        foreach ($requests as [$url, $payload]) {
            $this->patch($url, $payload)->assertRedirect(route('login'));
        }

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($requests as [$url, $payload]) {
                $this->actingAs($user)->patch($url, $payload)->assertForbidden();
            }
        }

        $this->assertSame(1, $first->refresh()->position);
        $this->assertTrue($first->is_active);
        $this->assertFalse($middle->refresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * The first activity of week 1 of a new program, reviewed by a coach, with the default rubric and an extra
     * `expected_files` key in its configuration.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function activity(array $attributes = []): Activity
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);

        return Activity::factory()->for($week, 'week')->create([
            'title' => 'Unit testing basics',
            'description' => 'Write your first tests.',
            'objectives' => 'Cover the happy path.',
            'instructions' => 'Write a test. Make it pass.',
            'position' => 1,
            'requires_coach_review' => true,
            'configuration' => [
                'scoring_dimensions' => Activity::DEFAULT_SCORING_DIMENSIONS,
                'expected_files' => ['tests/Feature/ExampleTest.php'],
            ],
            ...$attributes,
        ]);
    }

    /**
     * A submission field of the activity at the given position, mirroring its configuration as the seeder does.
     *
     * @param  array{key: string, label: string, type: string, required: bool, help: ?string, deprecated?: bool}  $configuration
     */
    private function field(Activity $activity, array $configuration, int $position): ActivityRequirement
    {
        return ActivityRequirement::factory()->for($activity)->create([
            'requirement_type' => 'submission_field',
            'title' => $configuration['label'],
            'description' => $configuration['help'],
            'is_required' => $configuration['required'],
            'position' => $position,
            'configuration' => $configuration,
        ]);
    }

    private function storeUrl(TrainingWeek $week): string
    {
        return route('admin.programs.activities.store', [$week->program_id, $week]);
    }

    private function editUrl(Activity $activity): string
    {
        return route('admin.programs.activities.edit', [$activity->week->program_id, $activity->week, $activity]);
    }

    private function updateUrl(Activity $activity): string
    {
        return route('admin.programs.activities.update', [$activity->week->program_id, $activity->week, $activity]);
    }

    /**
     * Three activities of one week at positions 1 to 3: "Unit testing basics" (see activity()), "Mocking drill" and
     * "Refactor kata".
     *
     * @return list<Activity>
     */
    private function threeActivities(): array
    {
        $first = $this->activity();

        return [
            $first,
            Activity::factory()->for($first->week, 'week')->create(['title' => 'Mocking drill', 'position' => 2]),
            Activity::factory()->for($first->week, 'week')->create(['title' => 'Refactor kata', 'position' => 3]),
        ];
    }

    /**
     * Assert that the activities hold positions 1, 2, 3, … in the given order.
     *
     * @param  list<Activity>  $activities
     */
    private function assertPositions(array $activities): void
    {
        $this->assertSame(
            range(1, count($activities)),
            array_map(fn (Activity $activity): int => $activity->refresh()->position, $activities),
        );
    }

    /**
     * The participant hands in the activity in the session: a submitted attempt with a submitted version.
     */
    private function handIn(TrainingSession $session, User $participant, Activity $activity): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($session)->for($participant, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
    }

    private function weekUrl(TrainingWeek $week): string
    {
        return route('admin.programs.weeks.show', [$week->program_id, $week]);
    }

    private function moveUrl(Activity $activity): string
    {
        return route('admin.programs.activities.move', [$activity->week->program_id, $activity->week, $activity]);
    }

    private function deactivateUrl(Activity $activity): string
    {
        return route('admin.programs.activities.deactivate', [$activity->week->program_id, $activity->week, $activity]);
    }

    private function reactivateUrl(Activity $activity): string
    {
        return route('admin.programs.activities.reactivate', [$activity->week->program_id, $activity->week, $activity]);
    }
}
