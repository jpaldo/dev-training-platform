<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Models\Activity;
use App\Models\AdminAuditLog;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumChecklistTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_checklist_tab_has_a_form_for_the_checklist_and_each_item_on_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checklist = $this->checklist();
        [$deliverable, $sessionLogs] = $this->items($checklist);
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
                'Removed items',
                'Retired deliverable',
            ], false)
            ->assertDontSee('action="'.$this->itemUrl($removed).'"', false);
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
        $session = TrainingSession::factory()->forWeek($checklist->week)->withParticipants($participant)->create();
        $session->mentors()->attach($mentor->id);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $mentor->id]);
        $review = GateEvaluation::factory()->for($checklist, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        $tick = GateCriterionEvaluation::factory()->for($review)->for($deliverable, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($admin)
            ->put($this->itemUrl($deliverable), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Checkout flow shipped', 'description' => 'Customers can pay.'])
            ->assertSessionHasNoErrors();

        $this->assertSame($deliverable->id, $tick->refresh()->gate_criterion_id);
        $this->assertSame(GateCriterionStatus::Met, $tick->status);
        $this->actingAs($participant)
            ->get(route('training.gates.show', [$session, $checklist->week, $checklist]))
            ->assertOk()
            ->assertSeeInOrder(['>✓</span>', 'Checkout flow shipped', 'Met', 'Customers can pay.'], false)
            ->assertDontSee('Feature shipped');
        $this->actingAs($participant)
            ->get(route('training.weeks.show', [$session, $checklist->week]))
            ->assertOk()
            ->assertSeeInOrder(['Checkout flow shipped', 'Met'])
            ->assertDontSee('Feature shipped');
        $this->actingAs($mentor)
            ->get(route('training.coach.gates.show', [$session, $checklist->week, $participant, $checklist]))
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
        $session = TrainingSession::factory()->forWeek($checklist->week)->withParticipants($participant)->create();
        $weekUrl = route('training.weeks.show', [$session, $checklist->week]);
        $this->actingAs($participant)->get($weekUrl)->assertSee('Choose Weekly Video');

        $this->actingAs($admin)
            ->put($this->itemUrl($video), ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Video walkthrough', 'description' => null])
            ->assertSessionHasNoErrors();

        $this->assertFalse($checklist->week->asksForWeeklyVideo());
        $this->actingAs($participant)->get($weekUrl)
            ->assertOk()
            ->assertDontSee('Choose Weekly Video');
        $this->actingAs($participant)
            ->get(route('training.video-recording.edit', [$session, $checklist->week]))
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

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $checklist = $this->checklist();
        [$deliverable] = $this->items($checklist);
        $checklistPayload = ['title' => 'Changed', 'description' => null];
        $itemPayload = ['type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Changed', 'description' => null];

        $this->put($this->checklistUrl($checklist), $checklistPayload)->assertRedirect(route('login'));
        $this->put($this->itemUrl($deliverable), $itemPayload)->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->put($this->checklistUrl($checklist), $checklistPayload)->assertForbidden();
            $this->actingAs($user)->put($this->itemUrl($deliverable), $itemPayload)->assertForbidden();
        }

        $this->assertSame('Week 1 checklist', $checklist->refresh()->title);
        $this->assertSame('Feature shipped', $deliverable->refresh()->title);
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
}
