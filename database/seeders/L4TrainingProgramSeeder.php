<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the L4 Accelerator Training program: week 1 holds the real curriculum, weeks 2 to 4 hold placeholder content
 * until the training team delivers theirs. Week N runs from Mon 9 Nov 2026 + 7×(N−1) days to six days later, so the
 * curriculum runs from 9 Nov to 6 Dec 2026. The program is created only when none of that name exists; otherwise the
 * seeder changes nothing, so edits made on the admin Curriculum pages stay. The one exception is a program none of
 * whose weeks has dates (seeded before weeks had them): its weeks get the dates above by number, once. Changing the
 * definition therefore only changes what new databases start with. Seeding is not an admin action, so nothing gets an
 * audit row.
 */
class L4TrainingProgramSeeder extends Seeder
{
    public const PROGRAM_NAME = 'L4 Accelerator Training';

    /**
     * The day week 1 starts, a Monday; every later week starts seven days after the one before.
     */
    public const FIRST_WEEK_STARTS_ON = '2026-11-09';

    private const PLACEHOLDER_TEXT = 'Placeholder — objective and instructions to come';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $existingProgram = Program::query()->where('name', self::PROGRAM_NAME)->first();

        if ($existingProgram !== null) {
            if ($this->scheduleUnscheduledProgram($existingProgram)) {
                $this->command?->info(self::PROGRAM_NAME.' had no week dates; its weeks now run from '.self::FIRST_WEEK_STARTS_ON.'.');
            } else {
                $this->command?->info(self::PROGRAM_NAME.' already exists; edit it on the admin Curriculum pages.');
            }

            return;
        }

        $definition = $this->definition();

        DB::transaction(function () use ($definition): void {
            $program = Program::query()->create([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_active' => true,
            ]);

            foreach ($definition['weeks'] as $weekDefinition) {
                $week = $this->seedWeek($program, $weekDefinition);

                foreach ($weekDefinition['activities'] as $index => $activityDefinition) {
                    $this->seedActivity($week, $index + 1, $activityDefinition);
                }

                $this->seedGate($week, $weekDefinition);
            }

            $this->ensureEveryActiveWeekHasARequiredChecklistItem($program);
        });
    }

    /**
     * The program as it should exist after seeding. Weeks are numbered from 1, keep `position` equal to `week_number`
     * and are dated by number (weekDates()); activities and checklist items take their position from their order in
     * the list.
     *
     * @return array{
     *     name: string,
     *     description: string,
     *     weeks: list<array{
     *         number: int,
     *         title: string,
     *         description: string,
     *         starts_on: string,
     *         ends_on: string,
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
     * @return array{number: int, title: string, description: string, starts_on: string, ends_on: string, activities: list<array<string, mixed>>, checklist: array<string, mixed>}
     */
    private function weekOne(): array
    {
        return [
            'number' => 1,
            'title' => 'Repo setup, feature specs and plans',
            'description' => 'Set up the starting repo with Claude Code, then build the /spec-feature and /plan-feature skills and use them to spec and plan the six core features.',
            ...$this->weekDates(1),
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
     * @return array{number: int, title: string, description: string, starts_on: string, ends_on: string, activities: list<array<string, mixed>>, checklist: array<string, mixed>}
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
            ...$this->weekDates($weekNumber),
            'activities' => $activities,
            'checklist' => $this->checklist($weekNumber, array_map(fn (array $activity): array => [
                'title' => $activity['title'].' deliverable submitted',
                'description' => 'The deliverable link for '.$activity['title'].' is submitted and works.',
            ], $activities)),
        ];
    }

    /**
     * The dates of week N: from FIRST_WEEK_STARTS_ON + 7×(N−1) days to six days later, as "2026-11-09" strings.
     *
     * @return array{starts_on: string, ends_on: string}
     */
    private function weekDates(int $weekNumber): array
    {
        $startsOn = CarbonImmutable::parse(self::FIRST_WEEK_STARTS_ON)->addDays(7 * ($weekNumber - 1));

        return [
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $startsOn->addDays(6)->toDateString(),
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
     * One activity: the given submission fields followed by optional notes, with the default scoring dimensions D1 to
     * D4 (still placeholders for every week until the training team supplies its own).
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
            'scoring_dimensions' => Activity::DEFAULT_SCORING_DIMENSIONS,
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
     * @param  array{number: int, title: string, description: string, starts_on: string, ends_on: string}  $definition
     */
    private function seedWeek(Program $program, array $definition): TrainingWeek
    {
        return $program->weeks()->create([
            'week_number' => $definition['number'],
            'title' => $definition['title'],
            'description' => $definition['description'],
            'position' => $definition['number'],
            'is_active' => true,
            'starts_on' => $definition['starts_on'],
            'ends_on' => $definition['ends_on'],
        ]);
    }

    /**
     * Date every week of an existing program none of whose weeks has dates, by week number as weekDates() does
     * (inactive weeks included, as the admin schedule form does), in one transaction. Returns whether it did: a program
     * with any dated week is left as it is, since an admin may have scheduled or re-dated it.
     */
    private function scheduleUnscheduledProgram(Program $program): bool
    {
        if ($program->weeks()->whereNotNull('starts_on')->exists()) {
            return false;
        }

        DB::transaction(function () use ($program): void {
            $program->weeks()->get()->each(
                fn (TrainingWeek $week): bool => $week->update($this->weekDates($week->week_number)),
            );
        });

        return true;
    }

    /**
     * Create the activity and its submission fields, each keyed by `configuration.key`.
     *
     * @param  array{title: string, description: string, objectives: string, instructions: string, fields: list<array{key: string, label: string, type: string, required: bool, help: string}>, scoring_dimensions: list<array{key: string, label: string, max: int}>}  $definition
     */
    private function seedActivity(TrainingWeek $week, int $position, array $definition): Activity
    {
        $activity = $week->activities()->create([
            'position' => $position,
            'title' => $definition['title'],
            'description' => $definition['description'],
            'objectives' => $definition['objectives'],
            'instructions' => $definition['instructions'],
            'configuration' => ['scoring_dimensions' => $definition['scoring_dimensions']],
            'requires_coach_review' => true,
            'is_active' => true,
        ]);

        foreach ($definition['fields'] as $index => $field) {
            $activity->requirements()->create([
                'requirement_type' => 'submission_field',
                'title' => $field['label'],
                'description' => $field['help'],
                'is_required' => $field['required'],
                'position' => $index + 1,
                'configuration' => $field,
            ]);
        }

        return $activity;
    }

    /**
     * Create the week's checklist: its one Gate and the Gate's items in list order.
     *
     * @param  array{checklist: array{title: string, description: string, items: list<array{type: string, title: string, description: string, required: bool}>}}  $definition
     */
    private function seedGate(TrainingWeek $week, array $definition): TrainingGate
    {
        $checklist = $definition['checklist'];

        $gate = $week->gates()->create([
            'position' => 1,
            'title' => $checklist['title'],
            'description' => $checklist['description'],
            'is_active' => true,
        ]);

        foreach ($checklist['items'] as $index => $item) {
            $gate->criteria()->create([
                'position' => $index + 1,
                'criterion_type' => $item['type'],
                'title' => $item['title'],
                'description' => $item['description'],
                'is_required' => $item['required'],
                'configuration' => null,
            ]);
        }

        return $gate;
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
