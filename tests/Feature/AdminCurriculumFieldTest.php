<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\AdminAuditLog;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
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
        [$participant, $program] = $this->participantInProgram($field->activity);

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Main takeaway', 'help' => 'One sentence is enough.', 'type' => 'text', 'required' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->get($this->submissionUrl($program, $field->activity))
            ->assertOk()
            ->assertSeeInOrder(['Main takeaway *', 'id="reflection" type="text" name="field_values[reflection]"', 'required', 'One sentence is enough.'], false)
            ->assertDontSee('Reflection')
            ->assertDontSee('What did you learn this week?');
    }

    public function test_a_saved_draft_keeps_its_value_under_the_same_key_after_a_label_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $program] = $this->participantInProgram($field->activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($program, $field->activity), ['field_values' => ['reflection' => 'Tests catch regressions.']])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Main takeaway', 'help' => 'What did you learn this week?', 'type' => 'textarea', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['reflection' => 'Tests catch regressions.'], ActivitySubmission::query()->sole()->versions()->sole()->field_values);
        $this->actingAs($participant)
            ->get($this->submissionUrl($program, $field->activity))
            ->assertOk()
            ->assertSeeInOrder(['Main takeaway', 'name="field_values[reflection]"', 'Tests catch regressions.'], false);
    }

    public function test_a_change_to_a_github_link_applies_to_the_next_draft_save(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $program] = $this->participantInProgram($field->activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($program, $field->activity), ['field_values' => ['reflection' => 'Tests catch regressions.']])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Reflection', 'help' => 'What did you learn this week?', 'type' => 'github_url', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->post($this->draftUrl($program, $field->activity), ['field_values' => ['reflection' => 'Still not a link.']])
            ->assertSessionHasErrors(['field_values.reflection' => GitHubUrl::MESSAGE]);
        $this->assertSame(['reflection' => 'Tests catch regressions.'], ActivitySubmission::query()->sole()->versions()->sole()->field_values);

        $this->actingAs($participant)
            ->post($this->draftUrl($program, $field->activity), ['field_values' => ['reflection' => 'https://github.com/example/repo']])
            ->assertSessionHasNoErrors();
    }

    public function test_making_a_field_required_blocks_the_next_submit_without_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $program] = $this->participantInProgram($field->activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($program, $field->activity), ['field_values' => ['reflection' => '']])
            ->assertSessionHasNoErrors();
        $version = ActivitySubmission::query()->sole()->versions()->sole();

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Reflection', 'help' => 'What did you learn this week?', 'type' => 'textarea', 'required' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->post(route('training.submission.submit', [$program, $field->activity->week, $field->activity, $version]))
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

    public function test_a_field_with_another_unoffered_type_keeps_it_on_save(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field(['key' => 'demo_date', 'label' => 'Demo date', 'type' => 'date', 'required' => false, 'help' => null]);

        $this->actingAs($admin)
            ->get($this->editUrl($field))
            ->assertOk()
            ->assertSeeInOrder(['Date. This type cannot change here.', 'name="type" value="date"'], false)
            ->assertDontSee('id="field-'.$field->id.'-type"', false);

        $this->actingAs($admin)
            ->put($this->updateUrl($field), ['label' => 'Demo day', 'help' => '', 'type' => 'date', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['key' => 'demo_date', 'label' => 'Demo day', 'type' => 'date', 'required' => false, 'help' => null],
            $field->refresh()->configuration,
        );
    }

    public function test_the_submission_fields_tab_has_move_remove_add_and_restore_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $notes = $this->addField($field->activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 2);
        $removed = $this->addField($field->activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);

        $this->actingAs($admin)
            ->get($this->editUrl($field))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.$this->fieldUrl('move', $field).'"',
                '<button type="submit" disabled name="direction" value="up" aria-label="Move Reflection up"',
                'name="direction" value="down" aria-label="Move Reflection down"',
                'action="'.$this->fieldUrl('destroy', $field).'" data-confirm="Remove Reflection? Participants&#039; answers to it are kept, but the field leaves the form."',
                'name="_method" value="DELETE"',
                'aria-label="Remove Reflection"',
                'action="'.$this->updateUrl($field).'"',
                'name="direction" value="up" aria-label="Move Notes up"',
                '<button type="submit" disabled name="direction" value="down" aria-label="Move Notes down"',
                'aria-label="Remove Notes"',
                'Add field',
                'action="'.route('admin.programs.fields.store', [$field->activity->week->program_id, $field->activity->week, $field->activity]).'"',
                'id="add-field-label"',
                '<option value="textarea" selected>Long text</option>',
                'id="add-field-help"',
                'Removed fields',
                'Retired link',
                'action="'.$this->fieldUrl('restore', $removed).'"',
                'aria-label="Restore Retired link"',
            ], false)
            ->assertDontSee('disabled name="direction" value="down" aria-label="Move Reflection down"', false)
            ->assertDontSee('disabled name="direction" value="up" aria-label="Move Notes up"', false)
            ->assertDontSee('action="'.$this->fieldUrl('move', $removed).'"', false)
            ->assertDontSee('action="'.$this->fieldUrl('restore', $notes).'"', false);
    }

    public function test_admin_adds_a_field_last_with_a_key_from_its_label_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $activity = $field->activity;
        $this->addField($activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);

        $this->actingAs($admin)
            ->post($this->storeUrl($activity), ['label' => 'Pull request link', 'help' => "Link the PR.\r\nIt must be open.", 'type' => 'github_url', 'required' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHas('status', 'Added "Pull request link".');
        $this->actingAs($admin)
            ->post($this->storeUrl($activity), ['label' => 'Pull request link', 'help' => '', 'type' => 'url', 'required' => '0'])
            ->assertSessionHasNoErrors();

        [$first, $second] = $activity->requirements()->where('position', '>', 3)->orderBy('position')->get()->all();
        $this->assertSame([
            'key' => 'pull_request_link',
            'label' => 'Pull request link',
            'type' => 'github_url',
            'required' => true,
            'help' => "Link the PR.\nIt must be open.",
        ], $first->configuration);
        $this->assertSame('submission_field', $first->requirement_type);
        $this->assertSame('Pull request link', $first->title);
        $this->assertSame("Link the PR.\nIt must be open.", $first->description);
        $this->assertTrue($first->is_required);
        $this->assertSame(4, $first->position);
        $this->assertSame('pull_request_link_2', $second->configuration['key']);
        $this->assertNull($second->configuration['help']);
        $this->assertFalse($second->is_required);
        $this->assertSame(5, $second->position);

        $audit = AdminAuditLog::query()->orderBy('id')->first();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_field_added', $audit->action);
        $this->assertSame([
            'program_id' => $activity->week->program_id,
            'training_week_id' => $activity->training_week_id,
            'activity_id' => $activity->id,
            'activity_requirement_id' => $first->id,
            'key' => 'pull_request_link',
            'label' => 'Pull request link',
            'type' => 'github_url',
            'required' => true,
            'position' => 4,
        ], $audit->metadata);
        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_a_new_field_shows_last_on_the_participant_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        [$participant, $program] = $this->participantInProgram($field->activity);

        $this->actingAs($admin)
            ->post($this->storeUrl($field->activity), ['label' => 'Pull request link', 'help' => 'Paste the PR URL.', 'type' => 'github_url', 'required' => '1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->get($this->submissionUrl($program, $field->activity))
            ->assertOk()
            ->assertSeeInOrder(['name="field_values[reflection]"', 'Pull request link *', 'name="field_values[pull_request_link]"', 'Paste the PR URL.'], false);
    }

    public function test_a_new_fields_key_skips_keys_that_removed_fields_still_hold(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $this->addField($field->activity, ['key' => 'pull_request_link', 'label' => 'Old PR link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 2);

        $this->actingAs($admin)
            ->post($this->storeUrl($field->activity), ['label' => 'Pull request link', 'help' => '', 'type' => 'url', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame('pull_request_link_2', $field->activity->requirements()->where('position', 3)->sole()->configuration['key']);
    }

    #[DataProvider('labelKeys')]
    public function test_a_new_fields_key_is_its_label_in_ascii_snake_case(string $label, string $key): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();

        $this->actingAs($admin)
            ->post($this->storeUrl($field->activity), ['label' => $label, 'help' => '', 'type' => 'text', 'required' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame($key, $field->activity->requirements()->where('position', 2)->sole()->configuration['key']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function labelKeys(): array
    {
        return [
            'capitals and an acronym' => ['GitHub URL', 'github_url'],
            'accents and punctuation' => ['Café notes (optional)!', 'cafe_notes_optional'],
            'hyphens' => ['Follow-up plan', 'follow_up_plan'],
            'over 40 characters' => [str_repeat('word ', 12), 'word_word_word_word_word_word_word_word'],
            'nothing usable' => ['¿¡ !!', 'field'],
        ];
    }

    public function test_a_suffixed_key_stays_within_40_characters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $label = str_repeat('abcde', 10);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($admin)
                ->post($this->storeUrl($field->activity), ['label' => $label, 'help' => '', 'type' => 'text', 'required' => '0'])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            [str_repeat('abcde', 8), str_repeat('abcde', 7).'abc_2'],
            $field->activity->requirements()->where('position', '>', 1)->orderBy('position')->get()->map(fn (ActivityRequirement $added): string => $added->configuration['key'])->all(),
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidNewFields')]
    public function test_an_invalid_new_field_fails_in_the_add_field_bag_and_creates_nothing(array $payload, string $setting, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();

        $this->actingAs($admin)
            ->post($this->storeUrl($field->activity), [...['label' => 'Pull request link', 'help' => null, 'type' => 'url', 'required' => '0'], ...$payload])
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHasErrorsIn('addField', [$setting => $message]);

        $this->assertDatabaseCount('activity_requirements', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidNewFields(): array
    {
        return [
            'blank label' => [['label' => ''], 'label', 'The label field is required.'],
            'label over 255 characters' => [['label' => str_repeat('a', 256)], 'label', 'The label field must not be greater than 255 characters.'],
            'help over 1000 characters' => [['help' => str_repeat('a', 1001)], 'help', 'The help field must not be greater than 1000 characters.'],
            'missing type' => [['type' => ''], 'type', 'The type field is required.'],
            'select is not offered' => [['type' => 'select'], 'type', 'The selected type is invalid.'],
            'date is not offered' => [['type' => 'date'], 'type', 'The selected type is invalid.'],
            'required not a boolean' => [['required' => 'maybe'], 'required', 'The required field must be true or false.'],
        ];
    }

    public function test_a_failed_add_shows_its_errors_beside_the_add_form_and_keeps_the_typed_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();

        $this->actingAs($admin)
            ->followingRedirects()
            ->post($this->storeUrl($field->activity), ['label' => '', 'help' => 'Typed help.', 'type' => 'github_url', 'required' => '1'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="field-'.$field->id.'-label"',
                'value="Reflection"',
                'id="add-field-label"',
                'value=""',
                'aria-invalid="true" aria-describedby="add-field-label-error"',
                '<p id="add-field-label-error" class="text-sm text-rose-200" role="alert">The label field is required.</p>',
                '<option value="github_url" selected>GitHub link</option>',
                '>Typed help.</textarea>',
                'checked',
            ], false)
            ->assertDontSee('id="field-'.$field->id.'-label-error"', false);
    }

    public function test_a_removed_field_leaves_the_form_while_its_answer_still_shows_on_the_submission_and_review_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field(['key' => 'reflection', 'label' => 'Main takeaway', 'type' => 'textarea', 'required' => true, 'help' => 'What did you learn this week?']);
        $activity = $field->activity;
        [$participant, $program] = $this->participantInProgram($activity);
        $coach = User::factory()->create(['role' => 'coach']);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now(), 'field_values' => ['reflection' => 'Tests catch regressions.']]);

        $this->actingAs($admin)
            ->delete($this->fieldUrl('destroy', $field))
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHas('status', 'Removed "Main takeaway".');

        $field->refresh();
        $this->assertSame(
            ['key' => 'reflection', 'label' => 'Main takeaway', 'type' => 'textarea', 'required' => false, 'help' => 'What did you learn this week?', 'deprecated' => true],
            $field->configuration,
        );
        $this->assertFalse($field->is_required);
        $this->assertSame(1, $field->position);
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_field_removed', $audit->action);
        $this->assertSame([
            'program_id' => $activity->week->program_id,
            'training_week_id' => $activity->training_week_id,
            'activity_id' => $activity->id,
            'activity_requirement_id' => $field->id,
            'changes' => ['removed' => ['from' => false, 'to' => true], 'required' => ['from' => true, 'to' => false]],
        ], $audit->metadata);

        $this->actingAs($participant)
            ->get(route('training.submission.show', [$program, $activity->week, $activity, $version]))
            ->assertOk()
            ->assertSeeInOrder(['Activity responses', 'Tests catch regressions.']);
        $this->actingAs($coach)
            ->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder(['Main takeaway', 'Tests catch regressions.']);
        $this->assertSame(['reflection' => 'Tests catch regressions.'], $version->refresh()->field_values);
    }

    public function test_a_restored_field_comes_back_optional_at_its_old_position_with_the_old_answer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field(['key' => 'reflection', 'label' => 'Reflection', 'type' => 'textarea', 'required' => true, 'help' => 'What did you learn this week?']);
        $activity = $field->activity;
        $this->addField($activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 2);
        [$participant, $program] = $this->participantInProgram($activity);
        $this->actingAs($participant)
            ->post($this->draftUrl($program, $activity), ['field_values' => ['reflection' => 'Tests catch regressions.', 'notes' => '']])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->delete($this->fieldUrl('destroy', $field))->assertSessionHasNoErrors();
        $this->actingAs($participant)
            ->get($this->submissionUrl($program, $activity))
            ->assertOk()
            ->assertSee('name="field_values[notes]"', false)
            ->assertDontSee('name="field_values[reflection]"', false)
            ->assertDontSee('Tests catch regressions.');

        $this->actingAs($admin)
            ->patch($this->fieldUrl('restore', $field))
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHas('status', 'Restored "Reflection".');

        $field->refresh();
        $this->assertSame(
            ['key' => 'reflection', 'label' => 'Reflection', 'type' => 'textarea', 'required' => false, 'help' => 'What did you learn this week?'],
            $field->configuration,
        );
        $this->assertFalse($field->is_required);
        $this->assertSame(1, $field->position);
        $audit = AdminAuditLog::query()->latest('id')->first();
        $this->assertSame('curriculum_field_restored', $audit->action);
        $this->assertSame(['removed' => ['from' => true, 'to' => false]], $audit->metadata['changes']);
        $this->assertSame($field->id, $audit->metadata['activity_requirement_id']);

        $this->actingAs($participant)
            ->get($this->submissionUrl($program, $activity))
            ->assertOk()
            ->assertSeeInOrder(['Reflection', 'name="field_values[reflection]"', 'Tests catch regressions.', 'name="field_values[notes]"'], false)
            ->assertDontSee('Reflection *');
    }

    public function test_removing_a_removed_field_or_restoring_a_field_on_the_form_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $removed = $this->addField($field->activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 2);

        $this->actingAs($admin)->delete($this->fieldUrl('destroy', $removed))->assertRedirect($this->editUrl($field).'#fields');
        $this->actingAs($admin)->patch($this->fieldUrl('restore', $field))->assertRedirect($this->editUrl($field).'#fields');

        $this->assertTrue($removed->refresh()->isRemovedField());
        $this->assertFalse($field->refresh()->isRemovedField());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_moving_a_field_swaps_it_with_the_next_field_on_the_form_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $activity = $field->activity;
        $removed = $this->addField($activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 2);
        $notes = $this->addField($activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 3);
        [$participant, $program] = $this->participantInProgram($activity);

        $this->actingAs($admin)
            ->patch($this->fieldUrl('move', $notes), ['direction' => 'up'])
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionHas('status', 'Moved "Notes" up.');

        $this->assertSame(1, $notes->refresh()->position);
        $this->assertSame(3, $field->refresh()->position);
        $this->assertSame(2, $removed->refresh()->position);
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_field_moved', $audit->action);
        $this->assertSame([
            'program_id' => $activity->week->program_id,
            'training_week_id' => $activity->training_week_id,
            'activity_id' => $activity->id,
            'activity_requirement_id' => $notes->id,
            'direction' => 'up',
            'changes' => ['position' => ['from' => 3, 'to' => 1]],
        ], $audit->metadata);
        $this->actingAs($participant)
            ->get($this->submissionUrl($program, $activity))
            ->assertOk()
            ->assertSeeInOrder(['name="field_values[notes]"', 'name="field_values[reflection]"'], false);

        $this->actingAs($admin)
            ->patch($this->fieldUrl('move', $notes), ['direction' => 'down'])
            ->assertSessionHas('status', 'Moved "Notes" down.');

        $this->assertSame(3, $notes->refresh()->position);
        $this->assertSame(1, $field->refresh()->position);
    }

    public function test_moving_the_first_field_up_or_the_last_down_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $notes = $this->addField($field->activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 2);
        $this->addField($field->activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);

        $this->actingAs($admin)
            ->patch($this->fieldUrl('move', $field), ['direction' => 'up'])
            ->assertRedirect($this->editUrl($field).'#fields')
            ->assertSessionMissing('status');
        $this->actingAs($admin)
            ->patch($this->fieldUrl('move', $notes), ['direction' => 'down'])
            ->assertSessionMissing('status');

        $this->assertSame(1, $field->refresh()->position);
        $this->assertSame(2, $notes->refresh()->position);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_move_needs_a_direction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $notes = $this->addField($field->activity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 2);

        $this->actingAs($admin)
            ->patch($this->fieldUrl('move', $notes), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');

        $this->assertSame(2, $notes->refresh()->position);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_actions_on_a_field_of_another_activity_or_a_requirement_that_is_not_a_field_are_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $field = $this->field();
        $activity = $field->activity;
        $otherActivity = Activity::factory()->for($activity->week, 'week')->create(['position' => 2]);
        $otherField = $this->addField($otherActivity, ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'help' => null], 1);
        $deliverable = ActivityRequirement::factory()->for($activity)->create(['title' => 'Legacy deliverable', 'position' => 2]);
        $removed = $this->addField($activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 3);
        $foreignRoute = fn (string $action): string => route("admin.programs.fields.{$action}", [$activity->week->program_id, $activity->week, $activity, $otherField]);

        $this->actingAs($admin)->patch($foreignRoute('move'), ['direction' => 'up'])->assertNotFound();
        $this->actingAs($admin)->delete($foreignRoute('destroy'))->assertNotFound();
        $this->actingAs($admin)->patch($foreignRoute('restore'))->assertNotFound();
        $this->actingAs($admin)->patch($this->fieldUrl('move', $deliverable), ['direction' => 'up'])->assertNotFound();
        $this->actingAs($admin)->delete($this->fieldUrl('destroy', $deliverable))->assertNotFound();
        $this->actingAs($admin)->patch($this->fieldUrl('restore', $deliverable))->assertNotFound();
        $this->actingAs($admin)->patch($this->fieldUrl('move', $removed), ['direction' => 'up'])->assertNotFound();
        $this->actingAs($admin)
            ->post(route('admin.programs.fields.store', [$activity->week->program_id, $otherActivity->week, Activity::factory()->create()]), ['label' => 'Stray', 'type' => 'text'])
            ->assertNotFound();

        $this->assertFalse($otherField->refresh()->isRemovedField());
        $this->assertSame(1, $otherField->position);
        $this->assertSame(2, $deliverable->refresh()->position);
        $this->assertSame(3, $removed->refresh()->position);
        $this->assertDatabaseCount('activity_requirements', 4);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_cannot_add_move_remove_or_restore_fields(): void
    {
        $field = $this->field();
        $removed = $this->addField($field->activity, ['key' => 'old_link', 'label' => 'Retired link', 'type' => 'url', 'required' => false, 'help' => null, 'deprecated' => true], 2);
        $requests = [
            fn (): array => ['post', $this->storeUrl($field->activity), ['label' => 'Stray', 'type' => 'text', 'required' => '0']],
            fn (): array => ['patch', $this->fieldUrl('move', $field), ['direction' => 'down']],
            fn (): array => ['delete', $this->fieldUrl('destroy', $field), []],
            fn (): array => ['patch', $this->fieldUrl('restore', $removed), []],
        ];

        foreach ($requests as $request) {
            [$method, $url, $payload] = $request();
            $this->$method($url, $payload)->assertRedirect(route('login'));

            foreach (['participant', 'coach'] as $role) {
                $this->actingAs(User::factory()->create(['role' => $role]))->$method($url, $payload)->assertForbidden();
            }

            auth()->logout();
        }

        $this->assertDatabaseCount('activity_requirements', 2);
        $this->assertFalse($field->refresh()->isRemovedField());
        $this->assertTrue($removed->refresh()->isRemovedField());
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
     * A checked-in participant of the activity's curriculum.
     *
     * @return array{User, Program}
     */
    private function participantInProgram(Activity $activity): array
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $program = $activity->week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);

        return [$participant, $program];
    }

    private function editUrl(ActivityRequirement $field): string
    {
        return route('admin.programs.activities.edit', [$field->activity->week->program_id, $field->activity->week, $field->activity]);
    }

    private function updateUrl(ActivityRequirement $field): string
    {
        return route('admin.programs.fields.update', [$field->activity->week->program_id, $field->activity->week, $field->activity, $field]);
    }

    private function storeUrl(Activity $activity): string
    {
        return route('admin.programs.fields.store', [$activity->week->program_id, $activity->week, $activity]);
    }

    /**
     * The URL of the field's move, destroy or restore action.
     */
    private function fieldUrl(string $action, ActivityRequirement $field): string
    {
        return route("admin.programs.fields.{$action}", [$field->activity->week->program_id, $field->activity->week, $field->activity, $field]);
    }

    private function submissionUrl(Program $program, Activity $activity): string
    {
        return route('training.submission.edit', [$program, $activity->week, $activity]);
    }

    private function draftUrl(Program $program, Activity $activity): string
    {
        return route('training.submission.draft', [$program, $activity->week, $activity]);
    }
}
