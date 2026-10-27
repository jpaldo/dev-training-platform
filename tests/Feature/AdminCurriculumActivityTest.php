<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
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
}
