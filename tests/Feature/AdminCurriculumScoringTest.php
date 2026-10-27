<?php

namespace Tests\Feature;

use App\Enums\ActivityScoreStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AdminAuditLog;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumScoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const LOCKED_MESSAGE = 'Scored submissions exist, so the dimensions can no longer change.';

    public function test_the_scoring_tab_has_nine_rows_filled_with_the_current_dimensions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $response = $this->actingAs($admin)
            ->get($this->editUrl($activity))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.$this->updateUrl($activity).'"',
                'name="_method" value="PUT"',
                'Maxima must add up to 100.',
                '<label for="scoring-dimension-0-label" class="sr-only">Dimension 1 label</label>',
                'name="dimensions[0][label]"',
                'value="Deliverable"',
                '<label for="scoring-dimension-0-max" class="sr-only">Dimension 1 maximum</label>',
                'name="dimensions[0][max]"',
                'value="40"',
                'name="dimensions[3][label]"',
                'value="Reflection"',
                'name="dimensions[3][max]"',
                'value="10"',
                'name="dimensions[4][label]"',
                'value=""',
                'name="dimensions[8][max]"',
                '>Save Scoring<',
            ], false)
            ->assertDontSee(self::LOCKED_MESSAGE);

        $this->assertSame(9, substr_count($response->getContent(), 'data-scoring-row>'));
    }

    public function test_admin_saves_dimensions_keyed_in_row_order_and_it_is_audited_while_other_configuration_survives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([
                ['Problem solving', '50'],
                ['', ''],
                ['Testing', '30'],
                ['Reflection', '20'],
            ])])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($activity).'#scoring')
            ->assertSessionHas('status', 'Saved the scoring dimensions of "Unit testing basics".');

        $dimensions = [
            ['key' => 'D1', 'label' => 'Problem solving', 'max' => 50],
            ['key' => 'D2', 'label' => 'Testing', 'max' => 30],
            ['key' => 'D3', 'label' => 'Reflection', 'max' => 20],
        ];
        $this->assertSame([
            'scoring_dimensions' => $dimensions,
            'expected_files' => ['tests/Feature/ExampleTest.php'],
        ], $activity->refresh()->configuration);

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('curriculum_scoring_updated', $audit->action);
        $this->assertSame([
            'program_id' => $activity->week->program_id,
            'training_week_id' => $activity->training_week_id,
            'activity_id' => $activity->id,
            'changes' => ['scoring_dimensions' => ['from' => Activity::DEFAULT_SCORING_DIMENSIONS, 'to' => $dimensions]],
        ], $audit->metadata);
    }

    public function test_new_dimensions_show_on_the_scoring_page_of_a_submitted_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $version = $this->submittedVersion($activity);

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([['Problem solving', '60'], ['Testing & verification', '40']])])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($admin)
            ->get(route('coach.scoring.edit', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'D1 60 · D2 40 · Total 100',
                'D1 &mdash; Problem solving evidence / reasoning (then points, max 60)',
                'name="dimensions[D1][points]" min="0" max="60"',
                'D2 &mdash; Testing &amp; verification evidence / reasoning (then points, max 40)',
                'name="dimensions[D2][points]" min="0" max="40"',
            ], false)
            ->assertDontSee('Deliverable');

        $this->assertSame(2, substr_count($response->getContent(), 'data-score-points data-max='));
    }

    public function test_maxima_that_do_not_add_up_to_100_fail_with_the_sum_beside_the_form_and_save_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([['Problem solving', '50'], ['Testing', '40']])])
            ->assertRedirect($this->editUrl($activity).'#scoring')
            ->assertSessionHasErrorsIn('scoring', ['dimensions' => 'The maxima add up to 90; they must add up to 100.']);

        $this->assertSame(Activity::DEFAULT_SCORING_DIMENSIONS, $activity->refresh()->scoringDimensions());
        $this->assertDatabaseCount('admin_audit_logs', 0);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([['Problem solving', '50'], ['Testing', '40']])])
            ->assertOk()
            ->assertSeeInOrder([
                'id="scoring-dimensions-error"',
                'The maxima add up to 90; they must add up to 100.',
                'aria-describedby="scoring-hint scoring-total-note scoring-dimensions-error"',
                'name="dimensions[0][label]"',
                'value="Problem solving"',
                'name="dimensions[1][max]"',
                'value="40"',
                'name="dimensions[2][label]"',
                'value=""',
            ], false)
            ->assertDontSee('value="Deliverable"', false);
    }

    /**
     * @param  array<string, mixed>  $dimensions
     */
    #[DataProvider('invalidDimensions')]
    public function test_invalid_rows_fail_in_the_scoring_bag_and_save_nothing(array $dimensions, string $key, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $dimensions])
            ->assertRedirect($this->editUrl($activity).'#scoring')
            ->assertSessionHasErrorsIn('scoring', [$key => $message]);

        $this->assertSame(Activity::DEFAULT_SCORING_DIMENSIONS, $activity->refresh()->scoringDimensions());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<int|string, mixed>, string, string}>
     */
    public static function invalidDimensions(): array
    {
        return [
            'ten rows' => [array_fill(0, 10, ['label' => 'Dimension', 'max' => '10']), 'dimensions', 'An activity has at most 9 dimensions.'],
            'label over 60 characters' => [[['label' => str_repeat('a', 61), 'max' => '100']], 'dimensions.0.label', 'The label field must not be greater than 60 characters.'],
            'maximum without a label' => [[['label' => 'Deliverable', 'max' => '60'], ['label' => '', 'max' => '40']], 'dimensions.1.label', 'The label field is required.'],
            'label without a maximum' => [[['label' => 'Deliverable', 'max' => '100'], ['label' => 'Testing', 'max' => '']], 'dimensions.1.max', 'The maximum field is required.'],
            'maximum of 0' => [[['label' => 'Deliverable', 'max' => '100'], ['label' => 'Testing', 'max' => '0']], 'dimensions.1.max', 'The maximum field must be between 1 and 100.'],
            'maximum over 100' => [[['label' => 'Deliverable', 'max' => '101']], 'dimensions.0.max', 'The maximum field must be between 1 and 100.'],
            'fractional maximum' => [[['label' => 'Deliverable', 'max' => '99.5'], ['label' => 'Testing', 'max' => '0.5']], 'dimensions.0.max', 'The maximum field must be an integer.'],
        ];
    }

    public function test_a_failed_row_shows_its_error_beside_the_row_and_keeps_the_typed_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        $this->actingAs($admin)
            ->followingRedirects()
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([['Problem solving', '60'], ['Testing', '0']])])
            ->assertOk()
            ->assertSeeInOrder([
                'name="dimensions[0][label]"',
                'value="Problem solving"',
                'name="dimensions[1][label]"',
                'value="Testing"',
                'name="dimensions[1][max]"',
                'value="0"',
                'aria-invalid="true" aria-describedby="scoring-dimension-1-max-error"',
                '<p id="scoring-dimension-1-max-error" class="col-span-2 col-start-2 text-sm text-rose-200" role="alert">The maximum field must be between 1 and 100.</p>',
            ], false)
            ->assertDontSee('id="scoring-dimensions-error"', false)
            ->assertDontSee('role="status"', false);
    }

    public function test_an_empty_set_of_dimensions_fails_and_saves_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();

        foreach ([['dimensions' => $this->rows([])], []] as $payload) {
            $this->actingAs($admin)
                ->put($this->updateUrl($activity), $payload)
                ->assertRedirect($this->editUrl($activity).'#scoring')
                ->assertSessionHasErrorsIn('scoring', ['dimensions' => 'Add at least one dimension.']);
        }

        $this->assertSame(Activity::DEFAULT_SCORING_DIMENSIONS, $activity->refresh()->scoringDimensions());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_once_a_version_of_the_activity_is_scored_the_tab_is_read_only_and_a_forged_save_fails(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $this->submittedVersion($activity)->activityScore()->create([
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => ActivityScoreStatus::Draft,
            'total_score' => 70,
            'performance_band' => 'Developing',
        ]);

        $this->actingAs($admin)
            ->get($this->editUrl($activity))
            ->assertOk()
            ->assertSeeInOrder([self::LOCKED_MESSAGE, 'D1', 'Deliverable', '40', 'D4', 'Reflection', '10', 'Total', '100'])
            ->assertDontSee('action="'.$this->updateUrl($activity).'"', false)
            ->assertDontSee('data-scoring-row', false);

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([['Problem solving', '60'], ['Testing', '40']])])
            ->assertRedirect($this->editUrl($activity).'#scoring')
            ->assertSessionHasErrorsIn('scoring', ['dimensions' => self::LOCKED_MESSAGE]);

        $this->assertSame(Activity::DEFAULT_SCORING_DIMENSIONS, $activity->refresh()->scoringDimensions());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_a_score_on_another_activity_does_not_lock_the_dimensions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $other = Activity::factory()->for($activity->week, 'week')->withScoringDimensions()->create(['position' => 2]);
        $this->submittedVersion($other)->activityScore()->create([
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => ActivityScoreStatus::Published,
            'total_score' => 88,
            'performance_band' => 'Proficient',
        ]);

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([['Problem solving', '100']])])
            ->assertSessionHasNoErrors();

        $this->assertSame([['key' => 'D1', 'label' => 'Problem solving', 'max' => 100]], $activity->refresh()->scoringDimensions());
    }

    public function test_an_unchanged_save_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $updatedAt = $activity->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put($this->updateUrl($activity), ['dimensions' => $this->rows([
                ['Deliverable', '40'],
                ['Code quality', '30'],
                ['Testing', '20'],
                ['Reflection', '10'],
            ])])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->editUrl($activity).'#scoring');

        $this->assertEquals($updatedAt, $activity->refresh()->updated_at);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_an_activity_of_another_week_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = $this->activity();
        $otherWeek = TrainingWeek::factory()->for($activity->week->program)->create(['week_number' => 2, 'position' => 2]);

        $this->actingAs($admin)
            ->put(route('admin.programs.scoring.update', [$activity->week->program_id, $otherWeek, $activity]), ['dimensions' => $this->rows([['Problem solving', '100']])])
            ->assertNotFound();

        $this->assertSame(Activity::DEFAULT_SCORING_DIMENSIONS, $activity->refresh()->scoringDimensions());
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $activity = $this->activity();
        $payload = ['dimensions' => $this->rows([['Problem solving', '100']])];

        $this->put($this->updateUrl($activity), $payload)->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->put($this->updateUrl($activity), $payload)->assertForbidden();
        }

        $this->assertSame(Activity::DEFAULT_SCORING_DIMENSIONS, $activity->refresh()->scoringDimensions());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * "Unit testing basics", the first activity of week 1 of a new program, scored on the default rubric and with an
     * extra `expected_files` key in its configuration.
     */
    private function activity(): Activity
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);

        return Activity::factory()->for($week, 'week')->create([
            'title' => 'Unit testing basics',
            'position' => 1,
            'configuration' => [
                'scoring_dimensions' => Activity::DEFAULT_SCORING_DIMENSIONS,
                'expected_files' => ['tests/Feature/ExampleTest.php'],
            ],
        ]);
    }

    /**
     * The nine rows the Scoring form sends: the given label and maximum pairs, then blank rows.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     * @return list<array{label: string, max: string}>
     */
    private function rows(array $rows): array
    {
        return array_map(
            fn (int $index): array => ['label' => $rows[$index][0] ?? '', 'max' => $rows[$index][1] ?? ''],
            range(0, 8),
        );
    }

    /**
     * A submitted first version of a submission of the activity.
     */
    private function submittedVersion(Activity $activity): SubmissionVersion
    {
        $submission = ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted', 'submitted_at' => now()]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
    }

    private function editUrl(Activity $activity): string
    {
        return route('admin.programs.activities.edit', [$activity->week->program_id, $activity->week, $activity]);
    }

    private function updateUrl(Activity $activity): string
    {
        return route('admin.programs.scoring.update', [$activity->week->program_id, $activity->week, $activity]);
    }
}
