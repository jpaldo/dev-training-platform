<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Closure;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class L4TrainingProgramSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeds_the_active_l4_program_with_four_weeks_of_activities_and_checklists(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $program = Program::query()->sole();
        $this->assertSame('L4 Accelerator Training', $program->name);
        $this->assertTrue($program->is_active);
        $this->assertSame([], $program->weekNumbersWithoutChecklist()->all());
        $this->assertDatabaseCount('training_weeks', 4);
        $this->assertDatabaseCount('activities', 13);
        $this->assertDatabaseCount('training_gates', 4);
        $this->assertDatabaseCount('gate_criteria', 19);
        $this->assertDatabaseCount('activity_requirements', 27);

        $weeks = $program->weeks()->orderBy('week_number')->with(['activities.requirements', 'gates.criteria'])->get();
        $this->assertSame([1, 2, 3, 4], $weeks->pluck('week_number')->all());
        $this->assertSame([1, 2, 3, 4], $weeks->pluck('position')->all());
        $this->assertTrue($weeks->every('is_active'));

        foreach ($weeks as $week) {
            $gate = $week->gates->sole();
            $this->assertTrue($gate->is_active);

            foreach ($week->activities as $activity) {
                $this->assertTrue($activity->is_active);
                $this->assertSame(['D1' => 40, 'D2' => 30, 'D3' => 20, 'D4' => 10], array_column($activity->scoringDimensions(), 'max', 'key'));
            }
        }

        $weekOne = $weeks->firstWhere('week_number', 1);
        $this->assertSame('Repo setup, feature specs and plans', $weekOne->title);
        $this->assertSame(
            [
                'Set up the starting repo' => [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                'Explore the starting skills' => [['skills_findings', 'textarea', true], ['notes', 'textarea', false]],
                'Choose the tech stack' => [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                'Create the /spec-feature skill' => [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                'Write the six feature specs' => [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                'Create the /plan-feature skill' => [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                'Plan the six features' => [['deliverable_url', 'github_url', true], ['pull_requests', 'textarea', true], ['notes', 'textarea', false]],
            ],
            $weekOne->activities->sortBy('position')->mapWithKeys(fn (Activity $activity): array => [
                $activity->title => $activity->requirements->sortBy('position')->map(fn (ActivityRequirement $field): array => [
                    $field->configuration['key'],
                    $field->configuration['type'],
                    $field->is_required,
                ])->values()->all(),
            ])->all(),
        );
        $this->assertSame(
            [
                ['deliverable', 'Repo setup'],
                ['deliverable', '/spec-feature skill'],
                ['deliverable', 'Feature specs (6)'],
                ['deliverable', '/plan-feature skill'],
                ['deliverable', 'Implementation plans (6)'],
                ['session_logs', 'Session logs committed for this week'],
                ['weekly_video', 'Video walkthrough'],
            ],
            $this->requiredChecklistItems($weekOne),
        );

        foreach ($weeks->where('week_number', '>', 1) as $week) {
            $this->assertSame(
                ["Placeholder Activity {$week->week_number}.1", "Placeholder Activity {$week->week_number}.2"],
                $week->activities->sortBy('position')->pluck('title')->all(),
            );

            foreach ($week->activities as $activity) {
                $this->assertSame(
                    [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                    $activity->requirements->sortBy('position')->map(fn (ActivityRequirement $field): array => [
                        $field->configuration['key'],
                        $field->configuration['type'],
                        $field->is_required,
                    ])->values()->all(),
                );
            }

            $this->assertSame(
                [
                    ['deliverable', "Placeholder Activity {$week->week_number}.1 deliverable submitted"],
                    ['deliverable', "Placeholder Activity {$week->week_number}.2 deliverable submitted"],
                    ['session_logs', 'Session logs committed for this week'],
                    ['weekly_video', 'Video walkthrough'],
                ],
                $this->requiredChecklistItems($week),
            );
        }
    }

    public function test_dates_the_four_weeks_monday_to_sunday_from_9_nov_to_6_dec_2026_without_an_audit_row(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $this->assertSame(
            [
                1 => ['2026-11-09', '2026-11-15'],
                2 => ['2026-11-16', '2026-11-22'],
                3 => ['2026-11-23', '2026-11-29'],
                4 => ['2026-11-30', '2026-12-06'],
            ],
            $this->weekDates(),
        );
        $this->assertSame('9 Nov – 6 Dec 2026', Program::query()->sole()->dateRangeLabel());
        $this->assertDatabaseEmpty('admin_audit_logs');
    }

    public function test_a_second_run_changes_nothing_even_after_admin_edits(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        TrainingWeek::query()->where('week_number', 1)->sole()->update(['title' => 'Kick-off']);
        $notes = Activity::query()->where('title', 'Set up the starting repo')->sole()->requirements()->where('title', 'Notes')->sole();
        $notes->update([
            'is_required' => false,
            'configuration' => [...$notes->configuration, 'required' => false, 'deprecated' => true],
        ]);
        GateCriterion::query()->where('title', 'Repo setup')->sole()->update(['title' => 'Repository set up']);
        $before = $this->curriculumRows();
        $this->travel(1)->hour();

        $this->artisan('db:seed', ['--class' => L4TrainingProgramSeeder::class])
            ->expectsOutput('L4 Accelerator Training already exists; edit it on the admin Curriculum pages.')
            ->assertExitCode(0);

        $this->assertSame($before, $this->curriculumRows());
        $this->assertDatabaseHas('training_weeks', ['week_number' => 1, 'title' => 'Kick-off']);
        $this->assertDatabaseHas('gate_criteria', ['id' => GateCriterion::query()->where('title', 'Repository set up')->sole()->id]);
        $this->assertFalse($notes->fresh()->is_required);
    }

    public function test_a_second_run_keeps_an_admins_week_dates(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        TrainingWeek::query()->where('week_number', 1)->sole()->update(['starts_on' => '2026-11-18', 'ends_on' => '2026-11-24']);
        TrainingWeek::query()->where('week_number', '>', 1)->update(['starts_on' => null, 'ends_on' => null]);

        $this->artisan('db:seed', ['--class' => L4TrainingProgramSeeder::class])
            ->expectsOutput('L4 Accelerator Training already exists; edit it on the admin Curriculum pages.')
            ->assertExitCode(0);

        $this->assertSame(
            [1 => ['2026-11-18', '2026-11-24'], 2 => [null, null], 3 => [null, null], 4 => [null, null]],
            $this->weekDates(),
        );
    }

    public function test_a_rerun_dates_the_weeks_of_a_program_without_week_dates_once_and_keeps_its_content(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        TrainingWeek::query()->update(['starts_on' => null, 'ends_on' => null]);
        TrainingWeek::query()->where('week_number', 3)->sole()->update(['title' => 'Kick-off', 'is_active' => false]);

        $this->artisan('db:seed', ['--class' => L4TrainingProgramSeeder::class])
            ->expectsOutput('L4 Accelerator Training had no week dates; its weeks now run from 2026-11-09.')
            ->assertExitCode(0);

        $this->assertSame(
            [
                1 => ['2026-11-09', '2026-11-15'],
                2 => ['2026-11-16', '2026-11-22'],
                3 => ['2026-11-23', '2026-11-29'],
                4 => ['2026-11-30', '2026-12-06'],
            ],
            $this->weekDates(),
        );
        $this->assertDatabaseHas('training_weeks', ['week_number' => 3, 'title' => 'Kick-off', 'is_active' => false]);
        $this->assertDatabaseCount('training_weeks', 4);
        $this->assertDatabaseEmpty('admin_audit_logs');

        $this->artisan('db:seed', ['--class' => L4TrainingProgramSeeder::class])
            ->expectsOutput('L4 Accelerator Training already exists; edit it on the admin Curriculum pages.')
            ->assertExitCode(0);
    }

    public function test_a_second_run_leaves_a_submission_and_a_review_untouched(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $activity = Activity::query()->where('title', 'Set up the starting repo')->sole();
        $submission = ActivitySubmission::factory()->for($activity)->for(User::factory(), 'participant')->create([
            'status' => SubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create([
            'status' => SubmissionStatus::Submitted,
            'field_values' => ['deliverable_url' => 'https://github.com/ana/l4', 'notes' => 'Ready for review.'],
            'submitted_at' => now(),
        ]);
        $gate = TrainingGate::query()->whereRelation('week', 'week_number', 1)->sole();
        $review = GateEvaluation::factory()->for($gate, 'gate')->for($submission->participant, 'participant')->create([
            'status' => GateEvaluationStatus::Passed,
            'evaluated_at' => now(),
        ]);
        $tick = GateCriterionEvaluation::factory()->create([
            'gate_evaluation_id' => $review->id,
            'gate_criterion_id' => $gate->criteria()->where('title', 'Repo setup')->sole()->id,
            'status' => GateCriterionStatus::Met,
            'evaluated_at' => now(),
        ]);
        $before = $this->attributesOf($submission, $version, $review, $tick);
        $this->travel(1)->hour();

        $this->seed(L4TrainingProgramSeeder::class);

        $this->assertSame($before, $this->attributesOf($submission, $version, $review, $tick));
        $this->assertSame(SubmissionStatus::Submitted, $submission->fresh()->status);
        $this->assertSame(GateCriterionStatus::Met, $tick->fresh()->status);
        $this->assertTrue($activity->fresh()->is_active);
        $this->assertTrue($tick->fresh()->criterion->is_required);
    }

    public function test_rejects_a_definition_that_leaves_an_active_week_without_a_required_checklist_item_and_seeds_nothing(): void
    {
        $this->assertThrows(
            function (): void {
                $this->runSeederWithDefinition(function (array $definition): array {
                    $definition['weeks'][1]['checklist']['items'] = [];

                    return $definition;
                });
            },
            RuntimeException::class,
            'L4 Accelerator Training has no required checklist item in week 2; every active week needs at least one.',
        );

        $this->assertDatabaseEmpty('programs');
        $this->assertDatabaseEmpty('training_weeks');
    }

    public function test_the_checklist_helper_reports_each_active_week_without_an_active_checklist_with_a_required_item(): void
    {
        $program = Program::factory()->create(['is_active' => true]);
        $week = fn (int $number, bool $isActive = true): TrainingWeek => TrainingWeek::factory()->for($program)->create(['week_number' => $number, 'position' => $number, 'is_active' => $isActive]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week(1), 'week'), 'gate')->create();
        GateCriterion::factory()->for(TrainingGate::factory()->for($week(2), 'week'), 'gate')->create(['is_required' => false]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week(3), 'week')->state(['is_active' => false]), 'gate')->create();
        $week(4);
        $week(5, isActive: false);

        $this->assertSame([2, 3, 4], $program->weekNumbersWithoutChecklist()->all());
    }

    /**
     * The week's required checklist items as [type, title] pairs, in checklist order.
     *
     * @return list<array{string, string}>
     */
    private function requiredChecklistItems(TrainingWeek $week): array
    {
        return $week->gates->sole()->criteria->where('is_required', true)->sortBy('position')->map(fn (GateCriterion $item): array => [
            $item->criterion_type,
            $item->title,
        ])->values()->all();
    }

    /**
     * Each L4 week's start and end date ("Y-m-d", null when unscheduled), by week number.
     *
     * @return array<int, array{?string, ?string}>
     */
    private function weekDates(): array
    {
        return TrainingWeek::query()->orderBy('week_number')->get()->mapWithKeys(fn (TrainingWeek $week): array => [
            $week->week_number => [$week->starts_on?->toDateString(), $week->ends_on?->toDateString()],
        ])->all();
    }

    /**
     * Every row of every seeded table, with all columns, by table.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function curriculumRows(): array
    {
        return collect(['programs', 'training_weeks', 'activities', 'training_gates', 'gate_criteria', 'activity_requirements'])
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            ])
            ->all();
    }

    /**
     * The stored attributes of each model, re-read from the database.
     *
     * @return list<array<string, mixed>>
     */
    private function attributesOf(Model ...$models): array
    {
        return array_map(fn (Model $model): array => $model->fresh()->getAttributes(), $models);
    }

    /**
     * Run the seeder with its definition changed by the given callback.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $changeDefinition
     */
    private function runSeederWithDefinition(Closure $changeDefinition): void
    {
        $seeder = new class($changeDefinition) extends L4TrainingProgramSeeder
        {
            public function __construct(private Closure $changeDefinition) {}

            protected function definition(): array
            {
                return ($this->changeDefinition)(parent::definition());
            }
        };

        $seeder->run();
    }
}
