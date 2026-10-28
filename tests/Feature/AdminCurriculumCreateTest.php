<?php

namespace Tests\Feature;

use App\Enums\ProgramStatus;
use App\Models\AdminAuditLog;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumCreateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_an_offered_curriculum_with_weeks_checklists_and_a_schedule_and_it_is_audited(): void
    {
        $this->travelTo('2026-11-02 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->post(route('admin.programs.store'), [
                'name' => 'Spring Cohort',
                'description' => "Line one\r\nLine two",
                'weeks_count' => '3',
                'starts_on' => '2026-11-09',
                'offered' => '1',
            ]);

        $program = Program::query()->sole();
        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $program).'#weeks')
            ->assertSessionHas('status', 'Created Spring Cohort with 3 weeks.');

        $this->assertSame('Spring Cohort', $program->name);
        $this->assertSame("Line one\nLine two", $program->description);
        $this->assertTrue($program->is_active);
        $this->assertSame(ProgramStatus::Upcoming, $program->status());
        $this->assertSame([], $program->weekNumbersWithoutChecklist()->all());

        $weeks = $program->weeks()->with('gates.criteria')->orderBy('week_number')->get();
        $this->assertSame([
            [1, 1, 'Week 1', true, '2026-11-09', '2026-11-15'],
            [2, 2, 'Week 2', true, '2026-11-16', '2026-11-22'],
            [3, 3, 'Week 3', true, '2026-11-23', '2026-11-29'],
        ], $weeks->map(fn (TrainingWeek $week): array => [
            $week->week_number,
            $week->position,
            $week->title,
            $week->is_active,
            $week->starts_on->toDateString(),
            $week->ends_on->toDateString(),
        ])->all());

        foreach ($weeks as $week) {
            $checklist = $week->gates->sole();
            $this->assertSame("Week {$week->week_number} checklist", $checklist->title);
            $this->assertTrue($checklist->is_active);

            $item = $checklist->criteria->sole();
            $this->assertSame(GateCriterion::TYPE_SESSION_LOGS, $item->criterion_type);
            $this->assertSame('Session logs committed for this week', $item->title);
            $this->assertTrue($item->is_required);
        }

        $audits = AdminAuditLog::query()->orderBy('id')->get();
        $this->assertSame(['curriculum_created', 'curriculum_scheduled'], $audits->pluck('action')->all());
        $this->assertSame($admin->id, $audits[0]->actor_id);
        $this->assertNull($audits[0]->target_user_id);
        $this->assertSame([
            'program_id' => $program->id,
            'name' => 'Spring Cohort',
            'weeks_count' => 3,
            'starts_on' => '2026-11-09',
            'offered' => true,
        ], $audits[0]->metadata);
        $this->assertSame($program->id, $audits[1]->metadata['program_id']);
        $this->assertSame('2026-11-09', $audits[1]->metadata['starts_on']);
    }

    public function test_a_curriculum_created_unchecked_and_without_a_start_date_is_not_offered_and_stays_unscheduled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.programs.store'), ['name' => 'Draft Curriculum', 'description' => '', 'weeks_count' => '1', 'starts_on' => '', 'offered' => '0'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Created Draft Curriculum with 1 week.');

        $program = Program::query()->sole();
        $this->assertNull($program->description);
        $this->assertFalse($program->is_active);
        $this->assertSame([], Program::query()->openToJoin()->pluck('id')->all());
        $this->assertSame(ProgramStatus::Unscheduled, $program->status());
        $this->assertSame([[1, null, null]], $program->weeks()->get()->map(fn (TrainingWeek $week): array => [
            $week->week_number,
            $week->starts_on,
            $week->ends_on,
        ])->all());
        $this->assertSame([], $program->weekNumbersWithoutChecklist()->all());

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_created', $audit->action);
        $this->assertSame([
            'program_id' => $program->id,
            'name' => 'Draft Curriculum',
            'weeks_count' => 1,
            'starts_on' => null,
            'offered' => false,
        ], $audit->metadata);
    }

    public function test_a_curriculum_created_without_the_offered_input_is_not_offered(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.programs.store'), ['name' => 'Draft Curriculum', 'weeks_count' => '2'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Program::query()->sole()->is_active);
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidCurricula')]
    public function test_invalid_input_returns_to_the_form_and_creates_nothing(array $input, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Program::factory()->create(['name' => 'Taken Name']);

        $this->actingAs($admin)
            ->from(route('admin.programs.create'))
            ->post(route('admin.programs.store'), [
                'name' => 'Spring Cohort',
                'weeks_count' => '4',
                ...$input,
            ])
            ->assertRedirect(route('admin.programs.create'))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(['Taken Name'], Program::query()->pluck('name')->all());
        $this->assertDatabaseCount('training_weeks', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidCurricula(): array
    {
        return [
            'blank name' => [['name' => ''], 'name', 'The name field is required.'],
            'name taken' => [['name' => 'Taken Name'], 'name', 'Another program already has this name.'],
            'blank weeks' => [['weeks_count' => ''], 'weeks_count', 'The number of weeks field is required.'],
            'no weeks' => [['weeks_count' => '0'], 'weeks_count', 'The number of weeks field must be between 1 and 12.'],
            'too many weeks' => [['weeks_count' => '13'], 'weeks_count', 'The number of weeks field must be between 1 and 12.'],
            'fractional weeks' => [['weeks_count' => '2.5'], 'weeks_count', 'The number of weeks field must be an integer.'],
            'not a date' => [['starts_on' => 'next monday-ish'], 'starts_on', 'The start date field must be a valid date.'],
            'offered not a boolean' => [['offered' => 'maybe'], 'offered', 'The offered to participants field must be true or false.'],
        ];
    }

    public function test_the_form_shows_a_failed_field_beside_it_and_keeps_the_typed_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Program::factory()->create(['name' => 'Taken Name']);

        $response = $this->actingAs($admin)
            ->from(route('admin.programs.create'))
            ->followingRedirects()
            ->post(route('admin.programs.store'), ['name' => 'Taken Name', 'weeks_count' => '6', 'starts_on' => '2026-11-09', 'offered' => '1'])
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.route('admin.programs.store').'"',
                'value="Taken Name"',
                'aria-invalid="true" aria-describedby="program-name-error"',
                '<p id="program-name-error" class="text-sm text-rose-200" role="alert">Another program already has this name.</p>',
                'value="6"',
                'value="2026-11-09"',
            ], false)
            ->assertSee('Offered to participants');

        $this->assertMatchesRegularExpression('/<input\s+id="program-offered"[^>]*\bchecked\b/', $response->getContent());
    }

    public function test_the_curricula_list_and_the_admin_dashboard_link_to_the_new_curriculum_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $createLink = '<a href="'.route('admin.programs.create').'"';

        $this->actingAs($admin)->get(route('admin.programs.index'))->assertOk()->assertSeeInOrder([$createLink, 'New program'], false);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSeeInOrder([$createLink, 'New program'], false);
        $form = $this->actingAs($admin)
            ->get(route('admin.programs.create'))
            ->assertOk()
            ->assertSee('New program')
            ->assertSee('action="'.route('admin.programs.store').'"', false)
            ->assertSee('max="12"', false)
            ->assertSee('Offered to participants')
            ->getContent();

        $this->assertMatchesRegularExpression('/<input\s+id="program-offered"[^>]*type="checkbox"[^>]*>/', $form);
        $this->assertDoesNotMatchRegularExpression('/<input\s+id="program-offered"[^>]*\bchecked\b/', $form);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $input = ['name' => 'Spring Cohort', 'weeks_count' => '4'];

        $this->get(route('admin.programs.create'))->assertRedirect(route('login'));
        $this->post(route('admin.programs.store'), $input)->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('admin.programs.create'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.programs.store'), $input)->assertForbidden();
        }

        $this->assertDatabaseCount('programs', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
