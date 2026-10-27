<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\AdminAuditLog;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Rules\GitHubUrl;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumFieldTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_submission_fields_tab_has_a_form_for_each_active_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $select = $this->addField($field->activity, ['key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'required' => true, 'help' => null, 'options' => ['Accept', 'Reject']], 2);
        $removed = $this->addField($field->activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);

        $this->actingAs($admin)
            ->get($this->editUrl($field))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.$this->updateUrl($field).'"',
                'name="_method" value="PUT"',
                'id="field-'.$field->id.'-label"',
                'value="Reflection"',
                '<option value="textarea" selected>Long text</option>',
                '>GitHub link</option>',
                '>What did you learn this week?</textarea>',
                'name="required" value="0"',
                'type="checkbox"',
                'aria-label="Save Field Reflection"',
                'action="'.$this->updateUrl($select).'"',
                'Select. Its options are kept and cannot change here.',
                'name="type" value="select"',
                'checked',
                'Removed fields',
                'Retired link',
            ], false)
            ->assertDontSee('action="'.$this->updateUrl($removed).'"', false);
    }

    public function test_admin_saves_a_fields_settings_and_it_is_audited_while_its_key_and_other_configuration_survive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), [
                'label' => 'Repository link',
                'help' => "Link the repository.\r\nMake it public.",
                'type' => 'github_url',
                'required' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHas('status', 'Saved "Repository link".');

        $field->refresh();
        $this->assertSame([
            'key' => 'reflection',
            'label' => 'Repository link',
            'type' => 'github_url',
            'required' => true,
            'help' => "Link the repository.\nMake it public.",
            'section' => 'artifact_context',
            'guidance' => ['what' => 'Your main takeaway.'],
        ], $field->configuration);
        $this->assertSame('Repository link', $field->title);
        $this->assertSame("Link the repository.\nMake it public.", $field->description);
        $this->assertTrue($field->is_required);
        $this->assertSame(1, $field->position);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_field_updated', $audit->action);
        $this->assertSame([
            'program_id' => $field->activity->week->program_id,
            'training_week_id' => $field->activity->training_week_id,
            'activity_id' => $field->activity_id,
            'activity_requirement_id' => $field->id,
            'changes' => [
                'label' => ['from' => 'Reflection', 'to' => 'Repository link'],
                'help' => ['from' => 'What did you learn this week?', 'to' => "Link the repository.\nMake it public."],
                'type' => ['from' => 'textarea', 'to' => 'github_url'],
                'required' => ['from' => false, 'to' => true],
            ],
        ], $audit->metadata);
    }

    public function test_the_participant_form_shows_the_new_label_help_control_and_required_at_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $session] = $this->participantInSession($field->activity);

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Main takeaway', 'help' => 'One sentence is enough.', 'type' => 'text', 'required' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->get($this->submissionUrl($session, $field->activity))
            ->assertOk()
            ->assertSeeInOrder(['Main takeaway *', 'id="reflection" type="text" name="field_values[reflection]"', 'required', 'One sentence is enough.'], false)
            ->assertDontSee('Reflection')
            ->assertDontSee('What did you learn this week?');
    }

    public function test_a_saved_draft_keeps_its_value_under_the_same_key_after_a_label_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $session] = $this->participantInSession($field->activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($session, $field->activity), ['field_values' => ['reflection' => 'Tests catch regressions.']])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Main takeaway', 'help' => 'What did you learn this week?', 'type' => 'textarea', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['reflection' => 'Tests catch regressions.'], ActivitySubmission::query()->sole()->versions()->sole()->field_values);
        $this->actingAs($participant)
            ->get($this->submissionUrl($session, $field->activity))
            ->assertOk()
            ->assertSeeInOrder(['Main takeaway', 'name="field_values[reflection]"', 'Tests catch regressions.'], false);
    }

    public function test_a_change_to_a_github_link_applies_to_the_next_draft_save(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $session] = $this->participantInSession($field->activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($session, $field->activity), ['field_values' => ['reflection' => 'Tests catch regressions.']])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Reflection', 'help' => 'What did you learn this week?', 'type' => 'github_url', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->post($this->draftUrl($session, $field->activity), ['field_values' => ['reflection' => 'Still not a link.']])
            ->assertSessionHasErrors(['field_values.reflection' => GitHubUrl::MESSAGE]);
        $this->assertSame(['reflection' => 'Tests catch regressions.'], ActivitySubmission::query()->sole()->versions()->sole()->field_values);

        $this->actingAs($participant)
            ->post($this->draftUrl($session, $field->activity), ['field_values' => ['reflection' => 'https://github.com/example/repo']])
            ->assertSessionHasNoErrors();
    }

    public function test_making_a_field_required_blocks_the_next_submit_without_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $session] = $this->participantInSession($field->activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($session, $field->activity), ['field_values' => ['reflection' => '']])
            ->assertSessionHasNoErrors();
        $version = ActivitySubmission::query()->sole()->versions()->sole();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Reflection', 'help' => 'What did you learn this week?', 'type' => 'textarea', 'required' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->post(route('training.submission.submit', [$session, $field->activity->week, $field->activity, $version]))
            ->assertSessionHasErrors(['field_values.reflection' => 'Reflection is required before submission.']);
        $this->assertTrue($version->refresh()->isDraft());
    }

    public function test_a_select_field_keeps_its_type_and_options(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field(['key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'required' => false, 'help' => null, 'options' => ['Accept', 'Reject']]);

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Your decision', 'help' => '', 'type' => 'select', 'required' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['key' => 'decision', 'label' => 'Your decision', 'type' => 'select', 'required' => true, 'help' => null, 'options' => ['Accept', 'Reject']],
            $field->refresh()->configuration,
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_fail_in_the_fields_bag_and_save_nothing(array $payload, string $setting, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $configuration = $field->configuration;

        $this->actingAs($admin)
            ->put($this->updateUrl($field), [...['label' => 'Reflection', 'help' => null, 'type' => 'textarea', 'required' => '0'], ...$payload])
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHasErrorsIn('field'.$field->id, [$setting => $message]);

        $this->assertSame($configuration, $field->refresh()->configuration);
        $this->assertSame('Reflection', $field->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'blank label' => [['label' => '', 'required' => '1'], 'label', 'The label field is required.'],
            'label over 255 characters' => [['label' => str_repeat('a', 256)], 'label', 'The label field must not be greater than 255 characters.'],
            'help over 1000 characters' => [['help' => str_repeat('a', 1001)], 'help', 'The help field must not be greater than 1000 characters.'],
            'missing type' => [['type' => ''], 'type', 'The type field is required.'],
            'type not offered' => [['type' => 'date'], 'type', 'The selected type is invalid.'],
            'select for a field that is not one' => [['type' => 'select'], 'type', 'The selected type is invalid.'],
            'required not a boolean' => [['required' => 'maybe'], 'required', 'The required field must be true or false.'],
        ];
    }

    public function test_a_failed_save_shows_its_errors_beside_its_own_form_and_keeps_the_typed_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $other = $this->addField($field->activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => 'Anything else.'], 2);
        $fieldId = 'field-'.$field->id;

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($this->updateUrl($field), ['label' => '', 'help' => 'Typed help.', 'type' => 'github_url', 'required' => '1'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="'.$fieldId.'-label"',
                'value=""',
                'aria-invalid="true" aria-describedby="'.$fieldId.'-label-error"',
                '<p id="'.$fieldId.'-label-error" class="text-sm text-rose-200" role="alert">The label field is required.</p>',
                '<option value="github_url" selected>GitHub link</option>',
                '>Typed help.</textarea>',
                'id="field-'.$other->id.'-label"',
                'value="Notes"',
                '<option value="textarea" selected>Long text</option>',
                '>Anything else.</textarea>',
            ], false)
            ->assertDontSee('id="field-'.$other->id.'-label-error"', false)
            ->assertDontSee('role="status"', false);
    }

    public function test_an_unchanged_save_with_browser_line_breaks_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field(['key' => 'reflection', 'label' => 'Reflection', 'type' => 'textarea', 'required' => false, 'help' => "What did you learn?\nWhat would you change?"]);
        $updatedAt = $field->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Reflection', 'help' => "What did you learn?\r\nWhat would you change?", 'type' => 'textarea', 'required' => '0'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($field).'#fields');

        $this->assertEquals($updatedAt, $field->refresh()->updated_at);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_extra_input_cannot_change_the_fields_key_position_or_other_configuration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), [
                'label' => 'Main takeaway',
                'help' => 'What did you learn this week?',
                'type' => 'textarea',
                'required' => '0',
                'key' => 'takeaway',
                'position' => 9,
                'deprecated' => '1',
                'requirement_type' => 'deliverable',
                'configuration' => ['key' => 'takeaway'],
            ])
            ->assertSessionHasNoErrors();

        $field->refresh();
        $this->assertSame('reflection', $field->configuration['key']);
        $this->assertArrayNotHasKey('deprecated', $field->configuration);
        $this->assertSame(1, $field->position);
        $this->assertSame('submission_field', $field->requirement_type);
        $this->assertSame(['label'], array_keys(AdminAuditLog::query()->sole()->metadata['changes']));
    }

    public function test_a_field_of_another_activity_a_requirement_that_is_not_a_field_or_a_removed_field_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $activity = $field->activity;
        $otherActivity = Activity::factory()->for($activity->week, 'week')->create(['position' => 2]);
        $otherField = $this->addField($otherActivity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 1);
        $deliverable = ActivityRequirement::factory()->for($activity)->create(['title' => 'Legacy deliverable', 'position' => 2]);
        $removed = $this->addField($activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);
        $payload = ['label' => 'Changed', 'help' => '', 'type' => 'text', 'required' => '0'];

        $this->actingAs($admin)
            ->put(route('admin.programs.fields.update', [$activity->week->program_id, $activity->week, $activity, $otherField]), $payload)
            ->assertNotFound();
        $this->actingAs($admin)->put($this->updateUrl($deliverable), $payload)->assertNotFound();
        $this->actingAs($admin)->put($this->updateUrl($removed), $payload)->assertNotFound();

        $this->assertSame('Notes', $otherField->refresh()->configuration['label']);
        $this->assertSame('Legacy deliverable', $deliverable->refresh()->title);
        $this->assertSame('Retired link', $removed->refresh()->configuration['label']);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $field = $this->field();
        $payload = ['label' => 'Changed', 'help' => '', 'type' => 'text', 'required' => '1'];

        $this->put($this->updateUrl($field), $payload)->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->put($this->updateUrl($field), $payload)->assertForbidden();
        }

        $this->assertSame('Reflection', $field->refresh()->configuration['label']);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * An optional long text "Reflection" field (key `reflection`) at position 1 of the first activity of week 1 of a
     * new program, with extra `section` and `guidance` keys in its configuration, unless another configuration is given.
     *
     * @param  ?array<string, mixed>  $configuration
     */
    private function field(?array $configuration = null): ActivityRequirement
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Unit testing basics', 'position' => 1]);

        return $this->addField($activity, $configuration ?? [
            'key' => 'reflection',
            'label' => 'Reflection',
            'type' => 'textarea',
            'required' => false,
            'help' => 'What did you learn this week?',
            'section' => 'artifact_context',
            'guidance' => ['what' => 'Your main takeaway.'],
        ], 1);
    }

    /**
     * A submission field of the activity at the given position, mirroring its configuration as the seeder does.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function addField(Activity $activity, array $configuration, int $position): ActivityRequirement
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

    /**
     * A checked-in participant of a session of the activity's program.
     *
     * @return array{User, TrainingSession}
     */
    private function participantInSession(Activity $activity): array
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $session = TrainingSession::factory()->forWeek($activity->week)->withParticipants($participant)->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $session];
    }

    private function editUrl(ActivityRequirement $field): string
    {
        return route('admin.programs.activities.edit', [$field->activity->week->program_id, $field->activity->week, $field->activity]);
    }

    private function updateUrl(ActivityRequirement $field): string
    {
        return route('admin.programs.fields.update', [$field->activity->week->program_id, $field->activity->week, $field->activity, $field]);
    }

    private function submissionUrl(TrainingSession $session, Activity $activity): string
    {
        return route('training.submission.edit', [$session, $activity->week, $activity]);
    }

    private function draftUrl(TrainingSession $session, Activity $activity): string
    {
        return route('training.submission.draft', [$session, $activity->week, $activity]);
    }
}
