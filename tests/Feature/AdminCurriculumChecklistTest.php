<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\AdminAuditLog;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ChecklistService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumChecklistTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_checklist_tab_has_a_form_for_the_checklist_and_each_item_on_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs, $video] = $this->items($checklist);
        $removed = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 4, onChecklist: false);

        $this->actingAs($admin)
            ->get($this->weekUrl($checklist))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.$this->checklistUrl($checklist).'"',
                'name="_method" value="PUT"',
                'id="checklist-title"',
                'value="Week 1 checklist"',
                '>Your mentor ticks each item once it is done.</textarea>',
                '>Save Checklist<',
                'action="'.$this->moveUrl($deliverable).'"',
                'name="_method" value="PATCH"',
                '<button type="submit" disabled name="direction" value="up" aria-label="Move Feature shipped up"',
                '<button type="submit"  name="direction" value="down" aria-label="Move Feature shipped down"',
                'action="'.$this->destroyUrl($deliverable).'" data-confirm="Remove Feature shipped? Mentors&#039; earlier ticks are kept; the item leaves the checklist."',
                'name="_method" value="DELETE"',
                'aria-label="Remove Feature shipped"',
                'action="'.$this->itemUrl($deliverable).'"',
                'id="checklist-item-'.$deliverable->id.'-title"',
                'value="Feature shipped"',
                '<option value="deliverable" selected>Deliverable</option>',
                '<option value="session_logs" >Session logs</option>',
                '<option value="weekly_video" >Weekly video</option>',
                '>The feature works end to end.</textarea>',
                'aria-label="Save Item Feature shipped"',
                'action="'.$this->itemUrl($sessionLogs).'"',
                '<option value="session_logs" selected>Session logs</option>',
                'action="'.$this->moveUrl($video).'"',
                '<button type="submit"  name="direction" value="up" aria-label="Move Video walkthrough up"',
                '<button type="submit" disabled name="direction" value="down" aria-label="Move Video walkthrough down"',
                'Add item',
                'action="'.$this->storeUrl($checklist).'"',
                'id="add-checklist-item-title"',
                '<option value="deliverable" selected>Deliverable</option>',
                '>Add Item<',
                'Removed items',
                'Retired deliverable',
                'action="'.$this->restoreUrl($removed).'"',
                'aria-label="Restore Retired deliverable"',
            ], false)
            ->assertDontSee('action="'.$this->itemUrl($removed).'"', false)
            ->assertDontSee('action="'.$this->moveUrl($removed).'"', false);
    }

    public function test_admin_saves_the_checklists_title_and_description_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();

        $this->actingAs($admin)
            ->put($this->checklistUrl($checklist), [
                'title' => 'Week 1 sign-off',
                'description' => "Your mentor ticks each item.\r\nAsk them when you are stuck.",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Saved "Week 1 sign-off".');

        $checklist->refresh();
        $this->assertSame('Week 1 sign-off', $checklist->title);
        $this->assertSame("Your mentor ticks each item.\nAsk them when you are stuck.", $checklist->description);
        $this->assertTrue($checklist->is_active);
        $this->assertSame(1, $checklist->position);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_checklist_updated', $audit->action);
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'changes' => [
                'title' => ['from' => 'Week 1 checklist', 'to' => 'Week 1 sign-off'],
                'description' => ['from' => 'Your mentor ticks each item once it is done.', 'to' => "Your mentor ticks each item.\nAsk them when you are stuck."],
            ],
        ], $audit->metadata);
    }

    public function test_admin_saves_an_items_type_title_and_description_and_it_is_audited_while_its_place_and_configuration_survive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs] = $this->items($checklist);
        $sessionLogs->update(['is_required' => false]);

        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), [
                'type' => GateCriterion::TYPE_SESSION_LOGS,
                'title' => 'Logs pushed',
                'description' => "Push the logs.\r\nTag the commit.",
                'position' => 9,
                'is_required' => '0',
                'configuration' => ['expected_files' => []],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Saved "Logs pushed".');

        $deliverable->refresh();
        $this->assertSame(GateCriterion::TYPE_SESSION_LOGS, $deliverable->criterion_type);
        $this->assertSame('Logs pushed', $deliverable->title);
        $this->assertSame("Push the logs.\nTag the commit.", $deliverable->description);
        $this->assertSame(1, $deliverable->position);
        $this->assertTrue($deliverable->is_required);
        $this->assertSame($checklist->id, $deliverable->training_gate_id);
        $this->assertSame(['expected_files' => ['README.md']], $deliverable->configuration);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_checklist_item_updated', $audit->action);
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'gate_criterion_id' => $deliverable->id,
            'changes' => [
                'type' => ['from' => GateCriterion::TYPE_DELIVERABLE, 'to' => GateCriterion::TYPE_SESSION_LOGS],
                'title' => ['from' => 'Feature shipped', 'to' => 'Logs pushed'],
                'description' => ['from' => 'The feature works end to end.', 'to' => "Push the logs.\nTag the commit."],
            ],
        ], $audit->metadata);
    }

    public function test_the_new_item_title_shows_to_the_participant_and_mentor_and_an_existing_tick_still_shows_as_met(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);
        $participant = User::factory()->create(['role' => 'participant']);
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = $checklist->week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $program->mentors()->attach($mentor->id);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $mentor->id]);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        $tick = GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Checkout flow shipped', 'description' => 'Customers can pay.'])
            ->assertSessionHasNoErrors();

        $this->assertSame($deliverable->id, $tick->refresh()->gate_criterion_id);
        $this->assertSame(GateCriterionStatus::Met, $tick->status);
        $this->actingAs($participant)
            ->get(route('training.gates.show', [$program, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['>✓</span>', 'Checkout flow shipped', 'Met', 'Customers can pay.'], false)
            ->assertDontSee('Feature shipped');
        $this->actingAs($participant)
            ->get(route('training.weeks.show', [$program, $checklist->week]))
            ->assertOk()
            ->assertSeeInOrder(['Checkout flow shipped', 'Met'])
            ->assertDontSee('Feature shipped');
        $this->actingAs($mentor)
            ->get(route('training.coach.gates.show', [$program, $checklist->week, $participant, $checklist]))
            ->assertOk()
            ->assertSeeInOrder([
                'id="criterion-'.$deliverable->id.'"',
                'checked',
                '<label for="criterion-'.$deliverable->id.'"',
                'Checkout flow shipped</label>',
                'Customers can pay.',
            ], false)
            ->assertDontSee('Feature shipped');
    }

    /**
     * @param  'session_logs'|'weekly_video'  $type
     */
    #[DataProvider('oneItemPerChecklistTypes')]
    public function test_a_second_session_logs_or_weekly_video_item_fails_in_the_items_bag_and_saves_nothing(string $type, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);

        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), ['type' => $type, 'title' => 'Renamed', 'description' => 'Changed.'])
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHasErrorsIn('checklistItem'.$deliverable->id, ['type' => $message]);

        $deliverable->refresh();
        $this->assertSame(GateCriterion::TYPE_DELIVERABLE, $deliverable->criterion_type);
        $this->assertSame('Feature shipped', $deliverable->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function oneItemPerChecklistTypes(): array
    {
        return [
            'session logs' => [GateCriterion::TYPE_SESSION_LOGS, 'This checklist already has a session logs item.'],
            'weekly video' => [GateCriterion::TYPE_WEEKLY_VIDEO, 'This checklist already has a weekly video item.'],
        ];
    }

    public function test_the_type_guard_leaves_out_the_item_itself_and_removed_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs, $video] = $this->items($checklist);
        $video->update(['is_required' => false]);

        $this->actingAs($admin)
            ->put($this->itemUrl($sessionLogs), ['type' => GateCriterion::TYPE_SESSION_LOGS, 'title' => 'Logs pushed', 'description' => null])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), ['type' => GateCriterion::TYPE_WEEKLY_VIDEO, 'title' => 'Demo video', 'description' => null])
            ->assertSessionHasNoErrors();

        $this->assertSame('Logs pushed', $sessionLogs->refresh()->title);
        $this->assertSame(GateCriterion::TYPE_WEEKLY_VIDEO, $deliverable->refresh()->criterion_type);
        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_changing_the_only_weekly_video_item_to_a_deliverable_stops_the_week_asking_for_the_video(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [, , $video] = $this->items($checklist);
        $participant = User::factory()->create(['role' => 'participant']);
        $program = $checklist->week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $weekUrl = route('training.weeks.show', [$program, $checklist->week]);
        $this->actingAs($participant)->get($weekUrl)->assertSee('Choose Weekly Video');

        $this->actingAs($admin)
            ->put($this->itemUrl($video), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Video walkthrough', 'description' => null])
            ->assertSessionHasNoErrors();

        $this->assertFalse($checklist->week->asksForWeeklyVideo());
        $this->actingAs($participant)->get($weekUrl)
            ->assertOk()
            ->assertDontSee('Choose Weekly Video');
        $this->actingAs($participant)
            ->get(route('training.video-recording.edit', [$program, $checklist->week]))
            ->assertNotFound();
    }

    public function test_an_item_removed_from_the_checklist_of_another_week_or_of_another_gate_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $removed = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 4, onChecklist: false);
        $otherWeek = TrainingWeek::factory()->for($checklist->week->program)->create(['week_number' => 2, 'position' => 2]);
        $otherWeeksItem = $this->addItem(TrainingGate::factory()->for($otherWeek, 'week')->create(), GateCriterion::TYPE_DELIVERABLE, 'Other week item', 1);
        $inactiveGate = TrainingGate::factory()->for($checklist->week, 'week')->create(['position' => 2, 'is_active' => false]);
        $inactiveGatesItem = $this->addItem($inactiveGate, GateCriterion::TYPE_DELIVERABLE, 'Old gate item', 1);
        $payload = ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Changed', 'description' => null];

        $this->actingAs($admin)->put($this->itemUrl($removed), $payload)->assertNotFound();
        $this->actingAs($admin)
            ->put(route('admin.programs.checklist-items.update', [$checklist->week->program_id, $checklist->week, $otherWeeksItem]), $payload)
            ->assertNotFound();
        $this->actingAs($admin)->put($this->itemUrl($inactiveGatesItem), $payload)->assertNotFound();

        $this->assertSame('Retired deliverable', $removed->refresh()->title);
        $this->assertSame('Other week item', $otherWeeksItem->refresh()->title);
        $this->assertSame('Old gate item', $inactiveGatesItem->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_checklist_of_a_week_without_one_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $inactiveGate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'Old checklist', 'is_active' => false]);

        $this->actingAs($admin)
            ->put(route('admin.programs.checklist.update', [$week->program_id, $week]), ['title' => 'Changed', 'description' => null])
            ->assertNotFound();

        $this->assertSame('Old checklist', $inactiveGate->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidChecklistDetails')]
    public function test_invalid_checklist_details_fail_in_the_checklist_bag_and_save_nothing(array $payload, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();

        $this->actingAs($admin)
            ->put($this->checklistUrl($checklist), $payload)
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHasErrorsIn('checklist', [$field => $message]);

        $checklist->refresh();
        $this->assertSame('Week 1 checklist', $checklist->title);
        $this->assertSame('Your mentor ticks each item once it is done.', $checklist->description);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidChecklistDetails(): array
    {
        return [
            'blank title' => [['title' => '', 'description' => 'New text.'], 'title', 'The title field is required.'],
            'title over 255 characters' => [['title' => str_repeat('a', 256)], 'title', 'The title field must not be greater than 255 characters.'],
            'description over 2000 characters' => [['title' => 'Week 1 checklist', 'description' => str_repeat('a', 2001)], 'description', 'The description field must not be greater than 2000 characters.'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidItemDetails')]
    public function test_invalid_item_details_fail_in_the_items_bag_and_save_nothing(array $payload, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);

        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), [...['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Feature shipped', 'description' => null], ...$payload])
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHasErrorsIn('checklistItem'.$deliverable->id, [$field => $message]);

        $deliverable->refresh();
        $this->assertSame('Feature shipped', $deliverable->title);
        $this->assertSame('The feature works end to end.', $deliverable->description);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidItemDetails(): array
    {
        return [
            'blank title' => [['title' => ''], 'title', 'The title field is required.'],
            'title over 255 characters' => [['title' => str_repeat('a', 256)], 'title', 'The title field must not be greater than 255 characters.'],
            'description over 2000 characters' => [['description' => str_repeat('a', 2001)], 'description', 'The description field must not be greater than 2000 characters.'],
            'missing type' => [['type' => ''], 'type', 'The type field is required.'],
            'unknown type' => [['type' => 'activity_completion'], 'type', 'The selected type is invalid.'],
        ];
    }

    public function test_a_failed_item_save_shows_its_errors_beside_its_own_form_and_keeps_the_typed_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs] = $this->items($checklist);
        $itemId = 'checklist-item-'.$deliverable->id;

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($this->itemUrl($deliverable), ['type' => GateCriterion::TYPE_WEEKLY_VIDEO, 'title' => 'Typed title', 'description' => 'Typed description.'])
            ->assertOk()
            ->assertSeeInOrder([
                'value="Week 1 checklist"',
                'id="'.$itemId.'-title"',
                'value="Typed title"',
                'aria-invalid="true" aria-describedby="'.$itemId.'-type-error"',
                '<option value="weekly_video" selected>Weekly video</option>',
                '<p id="'.$itemId.'-type-error" class="text-sm text-rose-200" role="alert">This checklist already has a weekly video item.</p>',
                '>Typed description.</textarea>',
                'id="checklist-item-'.$sessionLogs->id.'-title"',
                'value="Session logs committed"',
                '<option value="session_logs" selected>Session logs</option>',
            ], false)
            ->assertDontSee('id="checklist-item-'.$sessionLogs->id.'-type-error"', false)
            ->assertDontSee('id="checklist-title-error"', false)
            ->assertDontSee('role="status"', false);
    }

    public function test_a_failed_checklist_save_shows_its_error_beside_the_checklist_form_and_keeps_the_typed_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($this->checklistUrl($checklist), ['title' => '', 'description' => 'Typed but not saved.'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="checklist-title"',
                'value=""',
                'aria-invalid="true" aria-describedby="checklist-title-error"',
                '<p id="checklist-title-error" class="text-sm text-rose-200" role="alert">The title field is required.</p>',
                '>Typed but not saved.</textarea>',
                'id="checklist-item-'.$deliverable->id.'-title"',
                'value="Feature shipped"',
            ], false)
            ->assertDontSee('id="checklist-item-'.$deliverable->id.'-title-error"', false)
            ->assertDontSee('role="status"', false);
    }

    public function test_unchanged_saves_with_browser_line_breaks_write_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist(description: "Your mentor ticks each item.\nAsk when stuck.");
        [$deliverable] = $this->items($checklist);
        $deliverable->update(['description' => "Ship it.\nDemo it."]);
        $checklistUpdatedAt = $checklist->refresh()->updated_at;
        $itemUpdatedAt = $deliverable->refresh()->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put($this->checklistUrl($checklist), ['title' => 'Week 1 checklist', 'description' => "Your mentor ticks each item.\r\nAsk when stuck."])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist');
        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Feature shipped', 'description' => "Ship it.\r\nDemo it."])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist');

        $this->assertEquals($checklistUpdatedAt, $checklist->refresh()->updated_at);
        $this->assertEquals($itemUpdatedAt, $deliverable->refresh()->updated_at);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_admin_adds_an_item_last_on_the_checklist_after_removed_items_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $this->items($checklist);
        $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 4, onChecklist: false);

        $this->actingAs($admin)
            ->post($this->storeUrl($checklist), [
                'type' => GateCriterion::TYPE_DELIVERABLE,
                'title' => 'Demo recorded',
                'description' => "Record the demo.\r\nShare the link.",
                'position' => 1,
                'is_required' => '0',
                'configuration' => ['expected_files' => ['demo.mp4']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Added "Demo recorded".');

        $item = $checklist->criteria()->where('title', 'Demo recorded')->sole();
        $this->assertSame(GateCriterion::TYPE_DELIVERABLE, $item->criterion_type);
        $this->assertSame("Record the demo.\nShare the link.", $item->description);
        $this->assertSame(5, $item->position);
        $this->assertTrue($item->is_required);
        $this->assertNull($item->configuration);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_checklist_item_added', $audit->action);
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'gate_criterion_id' => $item->id,
            'type' => GateCriterion::TYPE_DELIVERABLE,
            'title' => 'Demo recorded',
            'position' => 5,
        ], $audit->metadata);
    }

    public function test_a_new_item_shows_last_as_awaiting_review_to_the_participant_and_unticked_to_the_mentor_while_a_completed_review_stays_completed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $deliverable = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        $sessionLogs = $this->addItem($checklist, GateCriterion::TYPE_SESSION_LOGS, 'Session logs committed', 2);
        [$program, $participant, $mentor] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::Passed]);
        GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);
        GateCriterionEvaluation::factory()->for($review)->for($sessionLogs, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($admin)
            ->post($this->storeUrl($checklist), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Demo recorded', 'description' => 'Record a short demo.'])
            ->assertSessionHasNoErrors();
        $item = $checklist->criteria()->where('title', 'Demo recorded')->sole();

        $this->assertSame(GateEvaluationStatus::Passed, $review->refresh()->status);
        $this->actingAs($participant)
            ->get(route('training.gates.show', [$program, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['COMPLETED', 'Feature shipped', 'Met', 'Session logs committed', 'Met', 'Demo recorded', 'Awaiting Review', 'Record a short demo.']);
        $reviewPage = $this->actingAs($mentor)
            ->get(route('training.coach.gates.show', [$program, $checklist->week, $participant, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['Feature shipped', 'Session logs committed', 'Demo recorded</label>', 'Record a short demo.'], false)
            ->getContent();
        $this->assertStringContainsString('checked', $this->reviewCheckbox($reviewPage, $deliverable));
        $this->assertStringNotContainsString('checked', $this->reviewCheckbox($reviewPage, $item));
    }

    public function test_a_review_saved_after_an_item_is_added_without_ticking_it_can_only_be_incomplete(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $deliverable = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        [$program, $participant, $mentor] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::Passed]);
        GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);
        $this->actingAs($admin)
            ->post($this->storeUrl($checklist), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Demo recorded', 'description' => null])
            ->assertSessionHasNoErrors();
        $reviewUrl = route('training.coach.gates.update', [$program, $checklist->week, $participant, $checklist]);
        $ticks = ['criteria' => [$deliverable->id => GateCriterionStatus::Met->value]];

        $this->actingAs($mentor)
            ->post($reviewUrl, ['status' => GateEvaluationStatus::Passed->value, ...$ticks])
            ->assertSessionHasErrors(['criteria' => 'Tick every checklist item to mark the week completed.']);
        $this->assertSame(GateEvaluationStatus::Passed, $review->refresh()->status);

        $this->actingAs($mentor)
            ->post($reviewUrl, ['status' => GateEvaluationStatus::NeedsRevision->value, ...$ticks])
            ->assertSessionHasNoErrors();

        $this->assertSame(GateEvaluationStatus::NeedsRevision, $review->refresh()->status);
        $this->actingAs($participant)
            ->get(route('training.gates.show', [$program, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['INCOMPLETE', 'Feature shipped', 'Met', 'Demo recorded', 'Missing']);
    }

    public function test_moving_an_item_changes_the_order_on_the_participant_gate_page_and_ticks_stay_with_their_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs, $video] = $this->items($checklist);
        [$program, $participant] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        $deliverableTick = GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);
        $sessionLogsTick = GateCriterionEvaluation::factory()->for($review)->for($sessionLogs, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);

        $this->actingAs($admin)
            ->patch($this->moveUrl($sessionLogs), ['direction' => 'up'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Moved "Session logs committed" up.');

        $this->assertSame(1, $sessionLogs->refresh()->position);
        $this->assertSame(2, $deliverable->refresh()->position);
        $this->assertSame(3, $video->refresh()->position);
        $this->assertDatabaseHas('gate_criterion_evaluations', ['id' => $deliverableTick->id, 'gate_criterion_id' => $deliverable->id, 'status' => 'met']);
        $this->assertDatabaseHas('gate_criterion_evaluations', ['id' => $sessionLogsTick->id, 'gate_criterion_id' => $sessionLogs->id, 'status' => 'not_met']);
        $this->actingAs($participant)
            ->get(route('training.gates.show', [$program, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['Session logs committed', 'Missing', 'Feature shipped', 'Met', 'Video walkthrough', 'Awaiting Review']);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_checklist_item_moved', $audit->action);
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'gate_criterion_id' => $sessionLogs->id,
            'direction' => 'up',
            'changes' => ['position' => ['from' => 2, 'to' => 1]],
        ], $audit->metadata);
    }

    public function test_an_item_moves_past_removed_items_and_the_first_and_last_items_on_the_checklist_stay_put(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $deliverable = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        $removed = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 2, onChecklist: false);
        $sessionLogs = $this->addItem($checklist, GateCriterion::TYPE_SESSION_LOGS, 'Session logs committed', 3);
        $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Older retired deliverable', 4, onChecklist: false);

        $this->actingAs($admin)
            ->patch($this->moveUrl($deliverable), ['direction' => 'up'])
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionMissing('status');
        $this->actingAs($admin)
            ->patch($this->moveUrl($sessionLogs), ['direction' => 'down'])
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionMissing('status');
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->actingAs($admin)
            ->patch($this->moveUrl($deliverable), ['direction' => 'down'])
            ->assertSessionHas('status', 'Moved "Feature shipped" down.');

        $this->assertSame(3, $deliverable->refresh()->position);
        $this->assertSame(1, $sessionLogs->refresh()->position);
        $this->assertSame(2, $removed->refresh()->position);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    /**
     * @param  'session_logs'|'weekly_video'  $type
     */
    #[DataProvider('oneItemPerChecklistTypes')]
    public function test_adding_a_second_session_logs_or_weekly_video_item_fails_in_the_add_bag_and_creates_nothing(string $type, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $this->items($checklist);

        $this->actingAs($admin)
            ->post($this->storeUrl($checklist), ['type' => $type, 'title' => 'Second one', 'description' => null])
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHasErrorsIn('addChecklistItem', ['type' => $message]);

        $this->assertSame(3, $checklist->criteria()->count());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_failed_add_shows_its_errors_beside_the_add_form_and_keeps_the_typed_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);

        $this->actingAs($admin)
            ->followingRedirects()
            ->post($this->storeUrl($checklist), ['type' => GateCriterion::TYPE_SESSION_LOGS, 'title' => '', 'description' => 'Typed description.'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="checklist-item-'.$deliverable->id.'-title"',
                'value="Feature shipped"',
                'id="add-checklist-item-title"',
                'value=""',
                'aria-invalid="true" aria-describedby="add-checklist-item-title-error"',
                '<p id="add-checklist-item-title-error" class="text-sm text-rose-200" role="alert">The title field is required.</p>',
                '<option value="session_logs" selected>Session logs</option>',
                '>Typed description.</textarea>',
            ], false)
            ->assertDontSee('id="checklist-item-'.$deliverable->id.'-title-error"', false)
            ->assertDontSee('role="status"', false);

        $this->assertSame(3, $checklist->criteria()->count());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_adding_to_a_week_without_a_checklist_or_moving_an_item_off_the_weeks_checklist_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $removed = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 4, onChecklist: false);
        $otherWeek = TrainingWeek::factory()->for($checklist->week->program)->create(['week_number' => 2, 'position' => 2]);
        $otherWeeksItem = $this->addItem(TrainingGate::factory()->for($otherWeek, 'week')->create(['is_active' => false]), GateCriterion::TYPE_DELIVERABLE, 'Other week item', 1);
        $inactiveGatesItem = $this->addItem(TrainingGate::factory()->for($checklist->week, 'week')->create(['position' => 2, 'is_active' => false]), GateCriterion::TYPE_DELIVERABLE, 'Old gate item', 1);

        $this->actingAs($admin)
            ->post(route('admin.programs.checklist-items.store', [$otherWeek->program_id, $otherWeek]), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'New', 'description' => null])
            ->assertNotFound();
        $this->actingAs($admin)->patch($this->moveUrl($removed), ['direction' => 'up'])->assertNotFound();
        $this->actingAs($admin)
            ->patch(route('admin.programs.checklist-items.move', [$checklist->week->program_id, $checklist->week, $otherWeeksItem]), ['direction' => 'up'])
            ->assertNotFound();
        $this->actingAs($admin)->patch($this->moveUrl($inactiveGatesItem), ['direction' => 'up'])->assertNotFound();

        $this->assertSame(1, $otherWeeksItem->gate->criteria()->count());
        $this->assertSame(4, $removed->refresh()->position);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_an_item_with_a_type_that_is_not_offered_keeps_it_read_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $legacy = $this->addItem($checklist, 'activity_completion', 'All activities handed in', 1);
        $itemId = 'checklist-item-'.$legacy->id;

        $this->actingAs($admin)
            ->get($this->weekUrl($checklist))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.$this->itemUrl($legacy).'"',
                'Activity Completion. This type cannot change here.',
                '<input type="hidden" name="type" value="activity_completion">',
                'id="'.$itemId.'-description"',
            ], false)
            ->assertDontSee('id="'.$itemId.'-type"', false);

        $this->actingAs($admin)
            ->put($this->itemUrl($legacy), ['type' => 'activity_completion', 'title' => 'Every activity handed in', 'description' => null])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put($this->itemUrl($legacy), ['type' => 'manual_check', 'title' => 'Checked by hand', 'description' => null])
            ->assertSessionHasErrorsIn('checklistItem'.$legacy->id, ['type' => 'The selected type is invalid.']);

        $legacy->refresh();
        $this->assertSame('activity_completion', $legacy->criterion_type);
        $this->assertSame('Every activity handed in', $legacy->title);
        $this->assertSame(['title' => ['from' => 'All activities handed in', 'to' => 'Every activity handed in']], AdminAuditLog::query()->sole()->metadata['changes']);
    }

    public function test_removing_an_item_no_mentor_ticked_deletes_it_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs, $video] = $this->items($checklist);
        [$program, $participant] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        $deliverableTick = GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($admin)
            ->delete($this->destroyUrl($sessionLogs))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Deleted "Session logs committed".');

        $this->assertModelMissing($sessionLogs);
        $this->assertSame(1, $deliverable->refresh()->position);
        $this->assertSame(3, $video->refresh()->position);
        $this->assertDatabaseHas('gate_criterion_evaluations', ['id' => $deliverableTick->id, 'gate_criterion_id' => $deliverable->id, 'status' => 'met']);
        $this->assertSame([], $checklist->week->program->weekNumbersWithoutChecklist()->all());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_checklist_item_removed', $audit->action);
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'gate_criterion_id' => $sessionLogs->id,
            'deleted' => true,
            'type' => GateCriterion::TYPE_SESSION_LOGS,
            'title' => 'Session logs committed',
            'position' => 2,
        ], $audit->metadata);
    }

    public function test_removing_an_item_a_mentor_ticked_retires_it_without_changing_any_tick_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs, $video] = $this->items($checklist);
        [$program, $participant] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);
        GateCriterionEvaluation::factory()->for($review)->for($sessionLogs, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);
        GateCriterionEvaluation::factory()->for($review)->for($video, 'criterion')->create(['status' => GateCriterionStatus::Met]);
        $ticksBefore = GateCriterionEvaluation::query()->orderBy('id')->get()->toArray();

        $this->actingAs($admin)
            ->delete($this->destroyUrl($deliverable))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Removed "Feature shipped".');

        $deliverable->refresh();
        $this->assertFalse($deliverable->is_required);
        $this->assertSame(1, $deliverable->position);
        $this->assertSame(['expected_files' => ['README.md']], $deliverable->configuration);
        $this->assertSame($ticksBefore, GateCriterionEvaluation::query()->orderBy('id')->get()->toArray());
        $this->assertSame([], $checklist->week->program->weekNumbersWithoutChecklist()->all());
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'gate_criterion_id' => $deliverable->id,
            'deleted' => false,
            'type' => GateCriterion::TYPE_DELIVERABLE,
            'title' => 'Feature shipped',
            'position' => 1,
        ], AdminAuditLog::query()->where('action', 'curriculum_checklist_item_removed')->sole()->metadata);
        $this->actingAs($admin)
            ->get($this->weekUrl($checklist))
            ->assertOk()
            ->assertSeeInOrder(['Removed items', 'Feature shipped', 'action="'.$this->restoreUrl($deliverable).'"'], false)
            ->assertDontSee('action="'.$this->destroyUrl($deliverable).'"', false);
    }

    public function test_a_retired_item_leaves_the_participant_and_mentor_checklists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs] = $this->items($checklist);
        [$program, $participant, $mentor] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);
        GateCriterionEvaluation::factory()->for($review)->for($sessionLogs, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($admin)->delete($this->destroyUrl($deliverable))->assertSessionHas('status', 'Removed "Feature shipped".');

        $this->actingAs($participant)
            ->get(route('training.gates.show', [$program, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['Session logs committed', 'Met', 'Video walkthrough', 'Awaiting Review'])
            ->assertDontSee('Feature shipped');
        $this->actingAs($mentor)
            ->get(route('training.coach.gates.show', [$program, $checklist->week, $participant, $checklist]))
            ->assertOk()
            ->assertSee('id="criterion-'.$sessionLogs->id.'"', false)
            ->assertDontSee('id="criterion-'.$deliverable->id.'"', false)
            ->assertDontSee('Feature shipped');
    }

    public function test_a_retired_item_leaves_the_missing_items_on_the_participants_curriculum_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs] = $this->items($checklist);
        $program = $checklist->week->program;
        Activity::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]), 'week')->create();
        $participant = User::factory()->create(['role' => 'participant']);
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $checklist->week->update(['starts_on' => today()->subWeek(), 'ends_on' => today()->subDay()]);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create([
            'status' => GateEvaluationStatus::NeedsRevision,
            'evaluated_at' => now()->subDay(),
        ]);
        GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);
        GateCriterionEvaluation::factory()->for($review)->for($sessionLogs, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);
        $programUrl = route('training.programs.show', $program);
        $this->actingAs($participant)
            ->get($programUrl)
            ->assertOk()
            ->assertSeeInOrder(['Not met in your Week 1 review:', '>Feature shipped</a>', '>Session logs committed</a>'], false);

        $this->actingAs($admin)->delete($this->destroyUrl($deliverable))->assertSessionHas('status', 'Removed "Feature shipped".');

        $this->actingAs($participant)
            ->get($programUrl)
            ->assertOk()
            ->assertSeeInOrder(['Not met in your Week 1 review:', '>Session logs committed</a>'], false)
            ->assertDontSee('>Feature shipped</a>', false);
    }

    public function test_restoring_an_item_puts_it_back_last_with_its_ticks_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $deliverable = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        $retired = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Demo recorded', 2, onChecklist: false);
        $sessionLogs = $this->addItem($checklist, GateCriterion::TYPE_SESSION_LOGS, 'Session logs committed', 3);
        $olderRetired = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Older retired deliverable', 4, onChecklist: false);
        [$program, $participant] = $this->programWithMentor($checklist);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);
        $retiredTick = GateCriterionEvaluation::factory()->for($review)->for($retired, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($admin)
            ->patch($this->restoreUrl($retired))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHas('status', 'Restored "Demo recorded".');

        $retired->refresh();
        $this->assertTrue($retired->is_required);
        $this->assertSame(5, $retired->position);
        $this->assertSame([1, 3, 4], [$deliverable->refresh()->position, $sessionLogs->refresh()->position, $olderRetired->refresh()->position]);
        $this->assertFalse($olderRetired->is_required);
        $this->assertDatabaseHas('gate_criterion_evaluations', ['id' => $retiredTick->id, 'gate_criterion_id' => $retired->id, 'status' => 'met']);
        $this->actingAs($participant)
            ->get(route('training.gates.show', [$program, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['Feature shipped', 'Missing', 'Session logs committed', 'Awaiting Review', 'Demo recorded', 'Met']);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_checklist_item_restored', $audit->action);
        $this->assertSame([
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
            'gate_criterion_id' => $retired->id,
            'changes' => [
                'removed' => ['from' => true, 'to' => false],
                'position' => ['from' => 2, 'to' => 5],
            ],
        ], $audit->metadata);

        $this->actingAs($admin)->patch($this->restoreUrl($retired))->assertSessionHasNoErrors();

        $this->assertSame(5, $retired->refresh()->position);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    public function test_the_last_item_on_the_checklist_cannot_be_removed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $onlyItem = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 2, onChecklist: false);

        $this->actingAs($admin)
            ->delete($this->destroyUrl($onlyItem))
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHasErrorsIn('checklist', ['item' => 'A checklist needs at least one item.']);
        $this->actingAs($admin)
            ->followingRedirects()
            ->delete($this->destroyUrl($onlyItem))
            ->assertOk()
            ->assertSeeInOrder(['role="alert" data-checklist-error', 'A checklist needs at least one item.', 'value="Week 1 checklist"'], false)
            ->assertDontSee('id="checklist-title-error"', false)
            ->assertDontSee('role="status"', false);

        $this->assertTrue($onlyItem->refresh()->is_required);
        $this->assertSame([], $checklist->week->program->weekNumbersWithoutChecklist()->all());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_removing_rechecks_the_last_item_under_the_checklist_lock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $first = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        $second = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Demo recorded', 2);
        $second->update(['is_required' => false]);

        $this->assertThrows(
            fn (): bool => app(ChecklistService::class)->removeItem($first, $admin),
            ValidationException::class,
            'A checklist needs at least one item.',
        );

        $this->assertTrue($first->refresh()->is_required);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @param  'session_logs'|'weekly_video'  $type
     */
    #[DataProvider('oneItemPerChecklistTypes')]
    public function test_restoring_a_second_session_logs_or_weekly_video_item_fails_in_the_checklist_bag_and_saves_nothing(string $type, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $this->items($checklist);
        $retired = $this->addItem($checklist, $type, 'Older item of this type', 4, onChecklist: false);

        $this->actingAs($admin)
            ->patch($this->restoreUrl($retired))
            ->assertRedirect($this->weekUrl($checklist).'#checklist')
            ->assertSessionHasErrorsIn('checklist', ['item' => $message]);

        $retired->refresh();
        $this->assertFalse($retired->is_required);
        $this->assertSame(4, $retired->position);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_removing_or_restoring_an_item_off_the_weeks_checklist_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        $this->items($checklist);
        $removed = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 4, onChecklist: false);
        $otherWeek = TrainingWeek::factory()->for($checklist->week->program)->create(['week_number' => 2, 'position' => 2]);
        $otherWeeksItem = $this->addItem(TrainingGate::factory()->for($otherWeek, 'week')->create(), GateCriterion::TYPE_DELIVERABLE, 'Other week item', 1);
        $inactiveGate = TrainingGate::factory()->for($checklist->week, 'week')->create(['position' => 2, 'is_active' => false]);
        $inactiveGatesItem = $this->addItem($inactiveGate, GateCriterion::TYPE_DELIVERABLE, 'Old gate item', 1);
        $inactiveGatesRemovedItem = $this->addItem($inactiveGate, GateCriterion::TYPE_DELIVERABLE, 'Old gate retired item', 2, onChecklist: false);
        $otherWeeksItemOnThisWeek = [$checklist->week->program_id, $checklist->week, $otherWeeksItem];

        $this->actingAs($admin)->delete($this->destroyUrl($removed))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.programs.checklist-items.destroy', $otherWeeksItemOnThisWeek))->assertNotFound();
        $this->actingAs($admin)->delete($this->destroyUrl($inactiveGatesItem))->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.programs.checklist-items.restore', $otherWeeksItemOnThisWeek))->assertNotFound();
        $this->actingAs($admin)->patch($this->restoreUrl($inactiveGatesRemovedItem))->assertNotFound();

        $this->assertFalse($removed->refresh()->is_required);
        $this->assertTrue($otherWeeksItem->refresh()->is_required);
        $this->assertTrue($inactiveGatesItem->refresh()->is_required);
        $this->assertFalse($inactiveGatesRemovedItem->refresh()->is_required);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);
        $removed = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Retired deliverable', 4, onChecklist: false);
        $checklistPayload = ['title' => 'Changed', 'description' => null];
        $itemPayload = ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Changed', 'description' => null];

        $this->put($this->checklistUrl($checklist), $checklistPayload)->assertRedirect(route('login'));
        $this->put($this->itemUrl($deliverable), $itemPayload)->assertRedirect(route('login'));
        $this->post($this->storeUrl($checklist), $itemPayload)->assertRedirect(route('login'));
        $this->patch($this->moveUrl($deliverable), ['direction' => 'down'])->assertRedirect(route('login'));
        $this->delete($this->destroyUrl($deliverable))->assertRedirect(route('login'));
        $this->patch($this->restoreUrl($removed))->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->put($this->checklistUrl($checklist), $checklistPayload)->assertForbidden();
            $this->actingAs($user)->put($this->itemUrl($deliverable), $itemPayload)->assertForbidden();
            $this->actingAs($user)->post($this->storeUrl($checklist), $itemPayload)->assertForbidden();
            $this->actingAs($user)->patch($this->moveUrl($deliverable), ['direction' => 'down'])->assertForbidden();
            $this->actingAs($user)->delete($this->destroyUrl($deliverable))->assertForbidden();
            $this->actingAs($user)->patch($this->restoreUrl($removed))->assertForbidden();
        }

        $this->assertSame('Week 1 checklist', $checklist->refresh()->title);
        $this->assertSame('Feature shipped', $deliverable->refresh()->title);
        $this->assertSame(1, $deliverable->position);
        $this->assertTrue($deliverable->is_required);
        $this->assertFalse($removed->refresh()->is_required);
        $this->assertSame(4, $checklist->criteria()->count());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * The active checklist "Week 1 checklist" of week 1 of a new program, which has one activity.
     */
    private function checklist(string $description = 'Your mentor ticks each item once it is done.'): TrainingGate
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['position' => 1]);

        return TrainingGate::factory()->for($week, 'week')->create([
            'title' => 'Week 1 checklist',
            'description' => $description,
            'position' => 1,
        ]);
    }

    /**
     * The checklist's three items, as the seeder gives them: the deliverable "Feature shipped" (with an
     * `expected_files` configuration), the session logs item and the weekly video item.
     *
     * @return array{GateCriterion, GateCriterion, GateCriterion}
     */
    private function items(TrainingGate $checklist): array
    {
        $deliverable = $this->addItem($checklist, GateCriterion::TYPE_DELIVERABLE, 'Feature shipped', 1);
        $deliverable->update(['description' => 'The feature works end to end.', 'configuration' => ['expected_files' => ['README.md']]]);

        return [
            $deliverable,
            $this->addItem($checklist, GateCriterion::TYPE_SESSION_LOGS, 'Session logs committed', 2),
            $this->addItem($checklist, GateCriterion::TYPE_WEEKLY_VIDEO, 'Video walkthrough', 3),
        ];
    }

    private function addItem(TrainingGate $gate, string $type, string $title, int $position, bool $onChecklist = true): GateCriterion
    {
        return GateCriterion::factory()->for($gate, 'gate')->create([
            'criterion_type' => $type,
            'title' => $title,
            'description' => null,
            'is_required' => $onChecklist,
            'position' => $position,
        ]);
    }

    private function weekUrl(TrainingGate $checklist): string
    {
        return route('admin.programs.weeks.show', [$checklist->week->program_id, $checklist->week]);
    }

    private function checklistUrl(TrainingGate $checklist): string
    {
        return route('admin.programs.checklist.update', [$checklist->week->program_id, $checklist->week]);
    }

    private function itemUrl(GateCriterion $item): string
    {
        return route('admin.programs.checklist-items.update', [$item->gate->week->program_id, $item->gate->week, $item]);
    }

    private function storeUrl(TrainingGate $checklist): string
    {
        return route('admin.programs.checklist-items.store', [$checklist->week->program_id, $checklist->week]);
    }

    private function moveUrl(GateCriterion $item): string
    {
        return route('admin.programs.checklist-items.move', [$item->gate->week->program_id, $item->gate->week, $item]);
    }

    private function destroyUrl(GateCriterion $item): string
    {
        return route('admin.programs.checklist-items.destroy', [$item->gate->week->program_id, $item->gate->week, $item]);
    }

    private function restoreUrl(GateCriterion $item): string
    {
        return route('admin.programs.checklist-items.restore', [$item->gate->week->program_id, $item->gate->week, $item]);
    }

    /**
     * The checklist's curriculum with one participant and their mentor.
     *
     * @return array{Program, User, User}
     */
    private function programWithMentor(TrainingGate $checklist): array
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = $checklist->week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $program->mentors()->attach($mentor->id);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $mentor->id]);

        return [$program, $participant, $mentor];
    }

    /**
     * The checkbox of a checklist item on the mentor's review page.
     */
    private function reviewCheckbox(string $page, GateCriterion $item): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*id="criterion-'.$item->id.'"[^>]*>/', $page, $match));

        return $match[0];
    }
}
