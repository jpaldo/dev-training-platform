<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\SubmissionWorkflowService;
use Closure;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class L4TrainingProgramSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeds_the_active_l4_program_with_four_weeks_of_placeholder_activities_and_checklists(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $program = Program::query()->sole();
        $this->assertSame('L4 Accelerator Training', $program->name);
        $this->assertTrue($program->is_active);
        $this->assertSame([], $program->weekNumbersWithoutChecklist()->all());

        $weeks = $program->weeks()->orderBy('week_number')->with(['activities.requirements', 'gates.criteria'])->get();
        $this->assertSame([1, 2, 3, 4], $weeks->pluck('week_number')->all());
        $this->assertSame([1, 2, 3, 4], $weeks->pluck('position')->all());
        $this->assertTrue($weeks->every('is_active'));

        foreach ($weeks as $week) {
            $this->assertSame(
                ["Placeholder Activity {$week->week_number}.1", "Placeholder Activity {$week->week_number}.2"],
                $week->activities->sortBy('position')->pluck('title')->all(),
            );

            foreach ($week->activities as $activity) {
                $this->assertTrue($activity->is_active);
                $this->assertSame(['D1' => 40, 'D2' => 30, 'D3' => 20, 'D4' => 10], array_column($activity->scoringDimensions(), 'max', 'key'));
                $this->assertSame(
                    [['deliverable_url', 'github_url', true], ['notes', 'textarea', false]],
                    $activity->requirements->sortBy('position')->map(fn (ActivityRequirement $field): array => [
                        $field->configuration['key'],
                        $field->configuration['type'],
                        $field->is_required,
                    ])->values()->all(),
                );
            }

            $gate = $week->gates->sole();
            $this->assertTrue($gate->is_active);
            $this->assertSame(
                [
                    ['deliverable', "Placeholder Activity {$week->week_number}.1 deliverable submitted"],
                    ['deliverable', "Placeholder Activity {$week->week_number}.2 deliverable submitted"],
                    ['session_logs', 'Session logs committed for this week'],
                    ['weekly_video', 'Weekly video chosen from Google Drive'],
                ],
                $gate->criteria->where('is_required', true)->sortBy('position')->map(fn (GateCriterion $item): array => [
                    $item->criterion_type,
                    $item->title,
                ])->values()->all(),
            );
        }
    }

    public function test_running_twice_updates_every_record_in_place_without_duplicates(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $firstRunIds = $this->seededIds();

        $this->seed(L4TrainingProgramSeeder::class);

        $this->assertSame($firstRunIds, $this->seededIds());
        $this->assertDatabaseCount('programs', 1);
        $this->assertDatabaseCount('training_weeks', 4);
        $this->assertDatabaseCount('activities', 8);
        $this->assertDatabaseCount('training_gates', 4);
        $this->assertDatabaseCount('gate_criteria', 16);
        $this->assertDatabaseCount('activity_requirements', 16);
    }

    public function test_running_again_renames_the_old_weekly_video_item_in_place(): void
    {
        $this->runSeederWithDefinition(function (array $definition): array {
            foreach ($definition['weeks'] as $weekIndex => $week) {
                $definition['weeks'][$weekIndex]['checklist']['items'][3]['title'] = 'Weekly video recording uploaded';
                $definition['weeks'][$weekIndex]['checklist']['items'][3]['description'] = 'A video recording walking through this week\'s work is uploaded.';
            }

            return $definition;
        });
        $oldItemIds = GateCriterion::query()->where('title', 'Weekly video recording uploaded')->orderBy('id')->pluck('id')->all();
        $this->assertCount(4, $oldItemIds);

        $this->seed(L4TrainingProgramSeeder::class);

        $this->assertDatabaseCount('gate_criteria', 16);
        $this->assertDatabaseMissing('gate_criteria', ['title' => 'Weekly video recording uploaded']);
        $renamedItems = GateCriterion::query()->whereKey($oldItemIds)->orderBy('id')->get();
        $this->assertSame(array_fill(0, 4, 'Weekly video chosen from Google Drive'), $renamedItems->pluck('title')->all());
        $this->assertSame(array_fill(0, 4, 'A video walking through this week\'s work is chosen from Google Drive.'), $renamedItems->pluck('description')->all());
        $this->assertTrue($renamedItems->every(fn (GateCriterion $item): bool => $item->is_required && $item->isWeeklyVideo()));
    }

    public function test_running_again_leaves_a_submission_made_between_runs_untouched(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $activity = Activity::query()->where('title', 'Placeholder Activity 1.1')->sole();
        $submission = ActivitySubmission::factory()->for($activity)->for(User::factory(), 'participant')->create([
            'status' => SubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create([
            'status' => SubmissionStatus::Submitted,
            'field_values' => ['deliverable_url' => 'https://github.com/ana/l4', 'notes' => 'Ready for review.'],
            'submitted_at' => now(),
        ]);
        $this->travel(1)->hour();

        $this->seed(L4TrainingProgramSeeder::class);

        $this->assertTrue($submission->fresh()->is($submission));
        $this->assertSame($submission->updated_at->toDateTimeString(), $submission->fresh()->updated_at->toDateTimeString());
        $this->assertSame(SubmissionStatus::Submitted, $submission->fresh()->status);
        $this->assertSame(['deliverable_url' => 'https://github.com/ana/l4', 'notes' => 'Ready for review.'], $version->fresh()->field_values);
        $this->assertTrue($activity->fresh()->is_active);
    }

    public function test_running_without_a_week_deactivates_the_week_its_activities_and_its_checklist(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $this->runSeederWithDefinition(fn (array $definition): array => [
            ...$definition,
            'weeks' => array_slice($definition['weeks'], 0, 3),
        ]);

        $weeks = TrainingWeek::query()->orderBy('week_number')->get();
        $this->assertSame([1 => true, 2 => true, 3 => true, 4 => false], $weeks->pluck('is_active', 'week_number')->all());
        $weekFour = $weeks->firstWhere('week_number', 4);
        $this->assertSame([false, false], $weekFour->activities()->pluck('is_active')->all());
        $this->assertFalse($weekFour->gates()->sole()->is_active);
        $this->assertSame(6, Activity::query()->where('is_active', true)->count());
    }

    public function test_running_without_an_activity_deactivates_it_and_drops_its_checklist_item(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $this->runSeederWithDefinition(function (array $definition): array {
            array_splice($definition['weeks'][0]['activities'], 1, 1);
            array_splice($definition['weeks'][0]['checklist']['items'], 1, 1);

            return $definition;
        });

        $this->assertFalse(Activity::query()->where('title', 'Placeholder Activity 1.2')->sole()->is_active);
        $gate = TrainingGate::query()->whereHas('week', fn (Builder $week): Builder => $week->where('week_number', 1))->sole();
        $this->assertSame(4, $gate->criteria()->count());
        $this->assertSame(
            ['Placeholder Activity 1.1 deliverable submitted', 'Session logs committed for this week', 'Weekly video chosen from Google Drive'],
            $gate->criteria()->where('is_required', true)->orderBy('position')->pluck('title')->all(),
        );
    }

    public function test_running_without_a_field_removes_it_from_the_submission_form_but_keeps_the_record(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);

        $this->runSeederWithDefinition(function (array $definition): array {
            $definition['weeks'][0]['activities'][0]['fields'] = [$definition['weeks'][0]['activities'][0]['fields'][0]];

            return $definition;
        });

        $activity = Activity::query()->where('title', 'Placeholder Activity 1.1')->sole();
        $this->assertSame(['deliverable_url'], array_keys(app(SubmissionWorkflowService::class)->fieldDefinitions($activity)));
        $notes = $activity->requirements()->where('title', 'Notes')->sole();
        $this->assertFalse($notes->is_required);
        $this->assertTrue($notes->configuration['deprecated']);
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
     * The ids of every seeded program, week, activity, Gate, checklist item and field, by table.
     *
     * @return array<string, list<int>>
     */
    private function seededIds(): array
    {
        return [
            'programs' => Program::query()->orderBy('id')->pluck('id')->all(),
            'training_weeks' => TrainingWeek::query()->orderBy('id')->pluck('id')->all(),
            'activities' => Activity::query()->orderBy('id')->pluck('id')->all(),
            'training_gates' => TrainingGate::query()->orderBy('id')->pluck('id')->all(),
            'gate_criteria' => GateCriterion::query()->orderBy('id')->pluck('id')->all(),
            'activity_requirements' => ActivityRequirement::query()->orderBy('id')->pluck('id')->all(),
        ];
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
