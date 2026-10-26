<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the L4 Accelerator Training program: week 1 holds the real curriculum, weeks 2 to 4 hold placeholder content
 * until the training team delivers theirs. Re-running updates every record in place; records the definition no longer
 * contains are deactivated, never deleted, so participants' submissions and reviews stay intact.
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
            'weeks' => [
                $this->weekOne(),
                ...array_map($this->placeholderWeek(...), range(2, 4)),
            ],
        ];
    }

    /**
     * Week 1: set up the starting repo, then build the /spec-feature and /plan-feature skills and use them to spec and
     * plan the six core features. Its checklist has one item per expected deliverable, then the session logs and
     * video walkthrough items.
     *
     * @return array{number: int, title: string, description: string, activities: list<array<string, mixed>>, checklist: array<string, mixed>}
     */
    private function weekOne(): array
    {
        return [
            'number' => 1,
            'title' => 'Repo setup, feature specs and plans',
            'description' => 'Set up the starting repo with Claude Code, then build the /spec-feature and /plan-feature skills and use them to spec and plan the six core features.',
            'activities' => [
                $this->activity(
                    'Set up the starting repo',
                    'Clone the starting repo and connect Claude Code through OpenRouter.',
                    'Claude Code runs in the starting repo through OpenRouter, and every session writes a session log.',
                    'Clone the starting repo, connect Claude Code through OpenRouter, and confirm with /status. Run one session and check that a row appears in activity-outputs/session-logs/.',
                    [$this->deliverableField('Repository link', 'Paste the GitHub link to your copy of the starting repo.')],
                ),
                $this->activity(
                    'Explore the starting skills',
                    'Try the skills that come with the starting repo.',
                    'You know how a skill is invoked directly and how Claude Code loads one from a plain-words request.',
                    'Explore the skills already in the starting repo. Invoke one with / and trigger another by describing the task in plain words. Note which loaded and why.',
                    [[
                        'key' => 'skills_findings',
                        'label' => 'Which skills loaded and why',
                        'type' => 'textarea',
                        'required' => true,
                        'help' => 'Name the skill you invoked with / and the one you triggered in plain words, and explain why each loaded.',
                    ]],
                ),
                $this->activity(
                    'Choose the tech stack',
                    'Pick the tech stack for the product and record it.',
                    'CLAUDE.md records the tech stack, so every later session builds on the same choices.',
                    'Choose the tech stack and record it in CLAUDE.md.',
                    [$this->deliverableField('CLAUDE.md link', 'Paste the GitHub link to the CLAUDE.md that records your tech stack.')],
                ),
                $this->activity(
                    'Create the /spec-feature skill',
                    'Build a skill that writes a feature spec with the product owner.',
                    'A reusable skill turns a feature ID into a complete spec by asking instead of guessing.',
                    'Create /spec-feature in .claude/skills/spec-feature/. It reads docs/specs/product.md and one feature ID, asks the product owner whatever it cannot answer instead of guessing, and writes docs/specs/features/F<n>-<name>.md from a spec template kept in a supporting file. It makes no technical choices.',
                    [$this->deliverableField('Skill link', 'Paste the GitHub link to .claude/skills/spec-feature/.')],
                ),
                $this->activity(
                    'Write the six feature specs',
                    'Spec the six core features with /spec-feature.',
                    'Each core feature has an approved spec with every open question answered.',
                    'Run /spec-feature for all 6 core features. Answer every open question, record each answer under Decisions, and mark the spec Approved.',
                    [$this->deliverableField('Feature specs link', 'Paste the GitHub link to docs/specs/features/ with the six approved specs.')],
                ),
                $this->activity(
                    'Create the /plan-feature skill',
                    'Build a skill that turns an approved spec into an implementation plan.',
                    'A reusable skill plans a feature from its approved spec and maps every acceptance criterion to tests.',
                    'Create /plan-feature in .claude/skills/plan-feature/. It runs only on an approved spec, reads the spec, CLAUDE.md and docs/rules/, and writes docs/plans/F<n>-<name>.md from a plan template. It maps every acceptance criterion to tests and never edits the spec.',
                    [$this->deliverableField('Skill link', 'Paste the GitHub link to .claude/skills/plan-feature/.')],
                ),
                $this->activity(
                    'Plan the six features',
                    'Plan the six core features with /plan-feature and merge them.',
                    'Each core feature has a reviewed plan, merged with its spec through a pull request.',
                    'Run /plan-feature on the 6 approved specs, review and edit each plan, and merge each feature\'s spec and plan through a pull request.',
                    [
                        $this->deliverableField('Implementation plans link', 'Paste the GitHub link to docs/plans/ with the six plans.'),
                        [
                            'key' => 'pull_requests',
                            'label' => 'Pull request links',
                            'type' => 'textarea',
                            'required' => true,
                            'help' => 'List the merged pull request for each feature, one link per line.',
                        ],
                    ],
                ),
            ],
            'checklist' => $this->checklist(1, [
                [
                    'title' => 'Repo setup',
                    'description' => 'The starting repo is cloned, Claude Code connects through OpenRouter, a session log row is committed in activity-outputs/session-logs/, and CLAUDE.md records the tech stack.',
                ],
                [
                    'title' => '/spec-feature skill',
                    'description' => 'The skill in .claude/skills/spec-feature/ reads the product spec and one feature ID, asks instead of guessing, writes from a spec template kept in a supporting file, and makes no technical choices.',
                ],
                [
                    'title' => 'Feature specs (6)',
                    'description' => 'All six specs are in docs/specs/features/, every open question is answered under Decisions, and each spec is marked Approved.',
                ],
                [
                    'title' => '/plan-feature skill',
                    'description' => 'The skill in .claude/skills/plan-feature/ runs only on an approved spec, reads the spec, CLAUDE.md and docs/rules/, writes from a plan template, maps every acceptance criterion to tests, and never edits the spec.',
                ],
                [
                    'title' => 'Implementation plans (6)',
                    'description' => 'All six plans are in docs/plans/, reviewed, and merged with their specs through one pull request per feature.',
                ],
            ]),
        ];
    }

    /**
     * One placeholder week: two activities and a checklist with one deliverable item per activity, the session logs
     * item and the video walkthrough item, all required.
     *
     * @return array{number: int, title: string, description: string, activities: list<array<string, mixed>>, checklist: array<string, mixed>}
     */
    private function placeholderWeek(int $weekNumber): array
    {
        $activities = array_map(
            fn (int $activityNumber): array => $this->activity(
                'Placeholder Activity '.$weekNumber.'.'.$activityNumber,
                self::PLACEHOLDER_TEXT,
                self::PLACEHOLDER_TEXT,
                self::PLACEHOLDER_TEXT,
                [$this->deliverableField('Deliverable link', 'Paste the GitHub link to your deliverable for this activity.')],
            ),
            [1, 2],
        );

        return [
            'number' => $weekNumber,
            'title' => 'Placeholder topic',
            'description' => 'Placeholder — the goals of this week are coming soon.',
            'activities' => $activities,
            'checklist' => $this->checklist($weekNumber, array_map(fn (array $activity): array => [
                'title' => $activity['title'].' deliverable submitted',
                'description' => 'The deliverable link for '.$activity['title'].' is submitted and works.',
            ], $activities)),
        ];
    }

    /**
     * A week's checklist: one required deliverable item per given deliverable, then the required session logs and
     * video walkthrough items. The video walkthrough is the weekly video item, so it shows the video the participant
     * chose from Google Drive.
     *
     * @param  list<array{title: string, description: string}>  $deliverables
     * @return array{title: string, description: string, items: list<array{type: string, title: string, description: string, required: bool}>}
     */
    private function checklist(int $weekNumber, array $deliverables): array
    {
        return [
            'title' => 'Week '.$weekNumber.' checklist',
            'description' => 'Your mentor ticks each item once it is done.',
            'items' => [
                ...array_map(fn (array $deliverable): array => [
                    'type' => GateCriterion::TYPE_DELIVERABLE,
                    ...$deliverable,
                    'required' => true,
                ], $deliverables),
                [
                    'type' => GateCriterion::TYPE_SESSION_LOGS,
                    'title' => 'Session logs committed for this week',
                    'description' => 'The session logs for every session of this week are committed to your repository.',
                    'required' => true,
                ],
                [
                    'type' => GateCriterion::TYPE_WEEKLY_VIDEO,
                    'title' => 'Video walkthrough',
                    'description' => 'A video walking through this week\'s work is chosen from Google Drive.',
                    'required' => true,
                ],
            ],
        ];
    }

    /**
     * One activity: the given submission fields followed by optional notes, with scoring dimensions D1 to D4 (still
     * placeholders for every week until the training team supplies its own).
     *
     * @param  list<array{key: string, label: string, type: string, required: bool, help: string}>  $fields
     * @return array{title: string, description: string, objectives: string, instructions: string, fields: list<array{key: string, label: string, type: string, required: bool, help: string}>, scoring_dimensions: list<array{key: string, label: string, max: int}>}
     */
    private function activity(string $title, string $description, string $objectives, string $instructions, array $fields): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'objectives' => $objectives,
            'instructions' => $instructions,
            'fields' => [
                ...$fields,
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
     * The required GitHub deliverable link field.
     *
     * @return array{key: string, label: string, type: string, required: bool, help: string}
     */
    private function deliverableField(string $label, string $help): array
    {
        return [
            'key' => 'deliverable_url',
            'label' => $label,
            'type' => 'github_url',
            'required' => true,
            'help' => $help,
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
        $weekNumbersWithoutChecklist = $program->weekNumbersWithoutChecklist();

        if ($weekNumbersWithoutChecklist->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                '%s has no required checklist item in week %s; every active week needs at least one.',
                $program->name,
                $weekNumbersWithoutChecklist->implode(', '),
            ));
        }
    }
}
