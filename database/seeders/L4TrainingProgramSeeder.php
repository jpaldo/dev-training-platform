<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the L4 Accelerator Training program with placeholder content until the training team delivers the real
 * curriculum. Re-running updates every record in place; records the definition no longer contains are deactivated,
 * never deleted, so participants' submissions and reviews stay intact.
 */
class L4TrainingProgramSeeder extends Seeder
{
    public const PROGRAM_NAME = 'L4 Accelerator Training';

    private const PLACEHOLDER_TEXT = 'Placeholder — objective and instructions to come';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $definition = $this->definition();

        DB::transaction(function () use ($definition): void {
            $program = Program::query()->updateOrCreate(
                ['name' => $definition['name']],
                ['description' => $definition['description'], 'is_active' => true],
            );

            $weekIds = [];
            $activityIds = [];
            $gateIds = [];

            foreach ($definition['weeks'] as $weekDefinition) {
                $week = $this->seedWeek($program, $weekDefinition);
                $weekIds[] = $week->id;

                foreach ($weekDefinition['activities'] as $index => $activityDefinition) {
                    $activityIds[] = $this->seedActivity($week, $index + 1, $activityDefinition)->id;
                }

                $gateIds[] = $this->seedGate($week, $weekDefinition)->id;
            }

            $this->deactivateUndefinedRecords($program, $weekIds, $activityIds, $gateIds);
            $this->ensureEveryActiveWeekHasARequiredChecklistItem($program);
        });
    }

    /**
     * The program as it should exist after seeding. Weeks are numbered from 1 and keep `position` equal to
     * `week_number`; activities and checklist items take their position from their order in the list.
     *
     * @return array{
     *     name: string,
     *     description: string,
     *     weeks: list<array{
     *         number: int,
     *         title: string,
     *         description: string,
     *         activities: list<array{
     *             title: string,
     *             description: string,
     *             objectives: string,
     *             instructions: string,
     *             fields: list<array{key: string, label: string, type: string, required: bool, help: string}>,
     *             scoring_dimensions: list<array{key: string, label: string, max: int}>
     *         }>,
     *         checklist: array{
     *             title: string,
     *             description: string,
     *             items: list<array{type: string, title: string, description: string, required: bool}>
     *         }
     *     }>
     * }
     */
    protected function definition(): array
    {
        return [
            'name' => self::PROGRAM_NAME,
            'description' => 'Placeholder — the L4 Accelerator Training curriculum is coming soon.',
            'weeks' => array_map($this->placeholderWeek(...), range(1, 4)),
        ];
    }

    /**
     * One placeholder week: two activities and a checklist with one deliverable item per activity, the session logs
     * item and the weekly video item, all required.
     *
     * @return array{number: int, title: string, description: string, activities: list<array<string, mixed>>, checklist: array<string, mixed>}
     */
    private function placeholderWeek(int $weekNumber): array
    {
        $activities = array_map(
            fn (int $activityNumber): array => $this->placeholderActivity($weekNumber, $activityNumber),
            [1, 2],
        );

        return [
            'number' => $weekNumber,
            'title' => 'Placeholder topic',
            'description' => 'Placeholder — the goals of this week are coming soon.',
            'activities' => $activities,
            'checklist' => [
                'title' => 'Week '.$weekNumber.' checklist',
                'description' => 'Your mentor ticks each item once it is done.',
                'items' => [
                    ...array_map(fn (array $activity): array => [
                        'type' => GateCriterion::TYPE_DELIVERABLE,
                        'title' => $activity['title'].' deliverable submitted',
                        'description' => 'The deliverable link for '.$activity['title'].' is submitted and works.',
                        'required' => true,
                    ], $activities),
                    [
                        'type' => GateCriterion::TYPE_SESSION_LOGS,
                        'title' => 'Session logs committed for this week',
                        'description' => 'The session logs for every session of this week are committed to your repository.',
                        'required' => true,
                    ],
                    [
                        'type' => GateCriterion::TYPE_WEEKLY_VIDEO,
                        'title' => 'Weekly video recording uploaded',
                        'description' => 'A video recording walking through this week\'s work is uploaded.',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * One placeholder activity with a required GitHub deliverable link, optional notes and scoring dimensions D1 to D4.
     *
     * @return array{title: string, description: string, objectives: string, instructions: string, fields: list<array{key: string, label: string, type: string, required: bool, help: string}>, scoring_dimensions: list<array{key: string, label: string, max: int}>}
     */
    private function placeholderActivity(int $weekNumber, int $activityNumber): array
    {
        return [
            'title' => 'Placeholder Activity '.$weekNumber.'.'.$activityNumber,
            'description' => self::PLACEHOLDER_TEXT,
            'objectives' => self::PLACEHOLDER_TEXT,
            'instructions' => self::PLACEHOLDER_TEXT,
            'fields' => [
                [
                    'key' => 'deliverable_url',
                    'label' => 'Deliverable link',
                    'type' => 'github_url',
                    'required' => true,
                    'help' => 'Paste the GitHub link to your deliverable for this activity.',
                ],
                [
                    'key' => 'notes',
                    'label' => 'Notes',
                    'type' => 'textarea',
                    'required' => false,
                    'help' => 'Anything your mentor should know about this deliverable.',
                ],
            ],
            'scoring_dimensions' => [
                ['key' => 'D1', 'label' => 'Deliverable', 'max' => 40],
                ['key' => 'D2', 'label' => 'Code quality', 'max' => 30],
                ['key' => 'D3', 'label' => 'Testing', 'max' => 20],
                ['key' => 'D4', 'label' => 'Reflection', 'max' => 10],
            ],
        ];
    }

    /**
     * @param  array{number: int, title: string, description: string}  $definition
     */
    private function seedWeek(Program $program, array $definition): TrainingWeek
    {
        return TrainingWeek::query()->updateOrCreate(
            ['program_id' => $program->id, 'week_number' => $definition['number']],
            [
                'title' => $definition['title'],
                'description' => $definition['description'],
                'position' => $definition['number'],
                'is_active' => true,
            ],
        );
    }

    /**
     * Seed the activity and its submission fields, keyed by `configuration.key`. Fields the definition no longer
     * contains are marked deprecated and optional, so they leave the form but keep their submitted values.
     *
     * @param  array{title: string, description: string, objectives: string, instructions: string, fields: list<array{key: string, label: string, type: string, required: bool, help: string}>, scoring_dimensions: list<array{key: string, label: string, max: int}>}  $definition
     */
    private function seedActivity(TrainingWeek $week, int $position, array $definition): Activity
    {
        $activity = Activity::query()->updateOrCreate(
            ['training_week_id' => $week->id, 'position' => $position],
            [
                'title' => $definition['title'],
                'description' => $definition['description'],
                'objectives' => $definition['objectives'],
                'instructions' => $definition['instructions'],
                'configuration' => ['scoring_dimensions' => $definition['scoring_dimensions']],
                'requires_coach_review' => true,
                'is_active' => true,
            ],
        );

        $existingFields = $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->get()
            ->keyBy(fn (ActivityRequirement $requirement): ?string => $requirement->configuration['key'] ?? null);

        foreach ($definition['fields'] as $index => $field) {
            $attributes = [
                'title' => $field['label'],
                'description' => $field['help'],
                'is_required' => $field['required'],
                'position' => $index + 1,
                'configuration' => $field,
            ];

            $existingField = $existingFields->get($field['key']);

            if ($existingField !== null) {
                $existingField->update($attributes);
            } else {
                $activity->requirements()->create(['requirement_type' => 'submission_field'] + $attributes);
            }
        }

        $definedKeys = array_column($definition['fields'], 'key');

        $existingFields
            ->reject(fn (ActivityRequirement $requirement, ?string $key): bool => in_array($key, $definedKeys, true))
            ->each(fn (ActivityRequirement $requirement): bool => $requirement->update([
                'is_required' => false,
                'configuration' => [...($requirement->configuration ?? []), 'required' => false, 'deprecated' => true],
            ]));

        return $activity;
    }

    /**
     * Seed the week's checklist: its one Gate and the Gate's items, keyed by position. Items past the end of the
     * definition become optional, which removes them from the checklist without deleting their review history.
     *
     * @param  array{checklist: array{title: string, description: string, items: list<array{type: string, title: string, description: string, required: bool}>}}  $definition
     */
    private function seedGate(TrainingWeek $week, array $definition): TrainingGate
    {
        $checklist = $definition['checklist'];

        $gate = TrainingGate::query()->updateOrCreate(
            ['training_week_id' => $week->id, 'position' => 1],
            ['title' => $checklist['title'], 'description' => $checklist['description'], 'is_active' => true],
        );

        foreach ($checklist['items'] as $index => $item) {
            GateCriterion::query()->updateOrCreate(
                ['training_gate_id' => $gate->id, 'position' => $index + 1],
                [
                    'criterion_type' => $item['type'],
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'is_required' => $item['required'],
                    'configuration' => null,
                ],
            );
        }

        $gate->criteria()->where('position', '>', count($checklist['items']))->update(['is_required' => false]);

        return $gate;
    }

    /**
     * Deactivate the program's weeks, activities and Gates that this run did not seed.
     *
     * @param  list<int>  $weekIds
     * @param  list<int>  $activityIds
     * @param  list<int>  $gateIds
     */
    private function deactivateUndefinedRecords(Program $program, array $weekIds, array $activityIds, array $gateIds): void
    {
        $programWeekIds = $program->weeks()->select('id');

        $program->weeks()->whereNotIn('id', $weekIds)->update(['is_active' => false]);
        Activity::query()->whereIn('training_week_id', $programWeekIds)->whereNotIn('id', $activityIds)->update(['is_active' => false]);
        TrainingGate::query()->whereIn('training_week_id', $programWeekIds)->whereNotIn('id', $gateIds)->update(['is_active' => false]);
    }

    /**
     * Every active week must end with at least one required item on an active checklist; otherwise the seed is rolled
     * back.
     *
     * @throws RuntimeException
     */
    private function ensureEveryActiveWeekHasARequiredChecklistItem(Program $program): void
    {
        $weekNumbersWithoutChecklist = $program->weeks()
            ->where('is_active', true)
            ->whereDoesntHave('gates', fn (Builder $gates): Builder => $gates
                ->where('is_active', true)
                ->whereHas('criteria', fn (Builder $criteria): Builder => $criteria->where('is_required', true)))
            ->orderBy('week_number')
            ->pluck('week_number');

        if ($weekNumbersWithoutChecklist->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                '%s has no required checklist item in week %s; every active week needs at least one.',
                $program->name,
                $weekNumbersWithoutChecklist->implode(', '),
            ));
        }
    }
}
