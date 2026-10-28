<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumWeekTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_saves_the_weeks_title_and_description_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations', 'description' => 'Set up the repo.']);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), [
                'title' => 'Getting started',
                'description' => 'Set up the repo and plan the features.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.weeks.show', [$week->program_id, $week]).'#details')
            ->assertSessionHas('status', 'Saved "Week 1".');

        $week->refresh();
        $this->assertSame('Getting started', $week->title);
        $this->assertSame('Set up the repo and plan the features.', $week->description);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame('curriculum_week_updated', $audit->action);
        $this->assertSame([
            'program_id' => $week->program_id,
            'training_week_id' => $week->id,
            'changes' => [
                'title' => ['from' => 'Foundations', 'to' => 'Getting started'],
                'description' => ['from' => 'Set up the repo.', 'to' => 'Set up the repo and plan the features.'],
            ],
        ], $audit->metadata);
    }

    public function test_the_week_page_shows_the_saved_details_in_its_form_with_the_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), ['title' => 'Getting started', 'description' => 'Plan the features.'])
            ->assertOk()
            ->assertSeeInOrder([
                'role="status"', 'Saved &quot;Week 1&quot;.',
                'action="'.route('admin.programs.weeks.update', [$week->program_id, $week]).'"',
                'name="_method" value="PUT"',
                'value="Getting started"',
                '>Plan the features.</textarea>',
                '>Save Details<',
            ], false);
    }

    public function test_participants_see_the_new_title_on_the_week_page_and_the_curriculum_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        $week = $this->week(['title' => 'Foundations']);
        $week->program->participants()->attach($participant, ['joined_at' => now()]);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), ['title' => 'Getting started', 'description' => 'Plan the six features.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)
            ->get(route('training.weeks.show', [$week->program, $week]))
            ->assertOk()
            ->assertSeeInOrder(['Getting started', 'Plan the six features.'])
            ->assertDontSee('Foundations');
        $this->actingAs($participant)
            ->get(route('training.programs.show', $week->program))
            ->assertOk()
            ->assertSeeInOrder(['Getting started', 'Plan the six features.'])
            ->assertDontSee('Foundations');
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidDetails')]
    public function test_invalid_details_fail_in_the_week_bag_and_save_nothing(array $payload, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations', 'description' => 'Set up the repo.']);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), $payload)
            ->assertRedirect(route('admin.programs.weeks.show', [$week->program_id, $week]).'#details')
            ->assertSessionHasErrorsIn('week', [$field => $message]);

        $week->refresh();
        $this->assertSame('Foundations', $week->title);
        $this->assertSame('Set up the repo.', $week->description);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidDetails(): array
    {
        return [
            'blank title' => [['title' => '', 'description' => 'New text.'], 'title', 'The title field is required.'],
            'title over 255 characters' => [['title' => str_repeat('a', 256)], 'title', 'The title field must not be greater than 255 characters.'],
            'description over 5000 characters' => [['title' => 'Foundations', 'description' => str_repeat('a', 5001)], 'description', 'The description field must not be greater than 5000 characters.'],
        ];
    }

    public function test_a_blank_title_shows_its_error_under_the_field_and_keeps_the_typed_description(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations', 'description' => 'Set up the repo.']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), ['title' => '', 'description' => 'Typed but not saved.'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-title"',
                'value=""',
                'aria-invalid="true" aria-describedby="week-title-error"',
                '<p id="week-title-error" class="text-sm text-rose-200" role="alert">The title field is required.</p>',
                '>Typed but not saved.</textarea>',
            ], false)
            ->assertDontSee('role="status"', false);
    }

    public function test_an_unchanged_save_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations', 'description' => null]);
        $updatedAt = $week->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), ['title' => 'Foundations', 'description' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.weeks.show', [$week->program_id, $week]).'#details');

        $this->assertEquals($updatedAt, $week->refresh()->updated_at);
        $this->assertNull($week->description);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_an_unchanged_save_with_browser_line_breaks_in_the_description_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations', 'description' => "Set up the repo.\nPlan the features.\nShip one."]);
        $updatedAt = $week->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), [
                'title' => 'Foundations',
                'description' => "Set up the repo.\r\nPlan the features.\rShip one.",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.weeks.show', [$week->program_id, $week]).'#details');

        $week->refresh();
        $this->assertEquals($updatedAt, $week->updated_at);
        $this->assertSame("Set up the repo.\nPlan the features.\nShip one.", $week->description);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_extra_input_cannot_change_the_weeks_number_position_status_or_program(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = $this->week(['title' => 'Foundations']);
        $otherProgram = Program::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$week->program_id, $week]), [
                'title' => 'Getting started',
                'week_number' => 7,
                'position' => 9,
                'is_active' => '0',
                'program_id' => $otherProgram->id,
            ])
            ->assertSessionHasNoErrors();

        $week->refresh();
        $this->assertSame('Getting started', $week->title);
        $this->assertSame(1, $week->week_number);
        $this->assertSame(1, $week->position);
        $this->assertTrue($week->is_active);
        $this->assertNotSame($otherProgram->id, $week->program_id);
        $this->assertSame(['title'], array_keys(AdminAuditLog::query()->sole()->metadata['changes']));
    }

    public function test_a_week_of_another_program_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $foreignWeek = $this->week(['title' => 'Foundations']);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$program, $foreignWeek]), ['title' => 'Getting started'])
            ->assertNotFound();

        $this->assertSame('Foundations', $foreignWeek->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $week = $this->week(['title' => 'Foundations']);
        $url = route('admin.programs.weeks.update', [$week->program_id, $week]);

        $this->put($url, ['title' => 'Getting started'])->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->put($url, ['title' => 'Getting started'])
                ->assertForbidden();
        }

        $this->assertSame('Foundations', $week->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * Week 1 of a new program.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function week(array $attributes = []): TrainingWeek
    {
        return TrainingWeek::factory()->create([
            'week_number' => 1,
            'position' => 1,
            ...$attributes,
        ]);
    }
}
