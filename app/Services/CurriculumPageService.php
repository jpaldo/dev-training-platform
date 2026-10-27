<?php

namespace App\Services;

use App\Enums\TrainingSessionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CurriculumPageService
{
    /**
     * Everything the programs list renders: every program by name, each with whether it is active, its number of
     * weeks and of training sessions, and a link to its page (one query).
     *
     * @return array{programs: list<array{name: string, status_label: string, status_tone: string, weeks_count: int, sessions_count: int, url: string}>}
     */
    public function indexData(): array
    {
        return [
            'programs' => Program::query()
                ->withCount(['weeks', 'trainingSessions'])
                ->orderBy('name')
                ->get()
                ->map(fn (Program $program): array => [
                    'name' => $program->name,
                    'status_label' => $program->is_active ? 'Active' : 'Inactive',
                    'status_tone' => $program->is_active ? 'success' : 'neutral',
                    'weeks_count' => (int) $program->weeks_count,
                    'sessions_count' => (int) $program->training_sessions_count,
                    'url' => route('admin.programs.show', $program),
                ])
                ->all(),
        ];
    }

    /**
     * Everything the program page renders: the program's name and description, the link back to the list, the
     * sessions notice (one query) and the weeks in number order, each with whether it is active, its number of active
     * activities and of items on its checklist (the required items of its active Gates) and a link to its page, read in
     * one query.
     *
     * @return array{name: string, description: ?string, indexUrl: string, notice: array{sessions: list<array{title: string, dates: string, status_label: string, status_tone: string, url: string}>, has_active: bool, tone: string, message: string}, weeks: list<array{number: int, label: string, title: string, status_label: string, status_tone: string, active_activity_count: int, checklist_item_count: int, url: string}>}
     */
    public function programData(Program $program): array
    {
        return [
            'name' => $program->name,
            'description' => $program->description,
            'indexUrl' => route('admin.programs.index'),
            'notice' => $this->sessionsNotice($program),
            'weeks' => $program->weeks()
                ->withCount([
                    'activities as active_activities_count' => fn (Builder $activities): Builder => $activities->where('is_active', true),
                    'checklistItems as checklist_items_count' => fn (Builder $items): Builder => $items
                        ->where('training_gates.is_active', true)
                        ->where('gate_criteria.is_required', true),
                ])
                ->orderBy('week_number')
                ->get()
                ->map(fn (TrainingWeek $week): array => [
                    'number' => $week->week_number,
                    'label' => "Week {$week->week_number}",
                    'title' => $week->title,
                    'status_label' => $week->is_active ? 'Active' : 'Inactive',
                    'status_tone' => $week->is_active ? 'success' : 'neutral',
                    'active_activity_count' => (int) $week->active_activities_count,
                    'checklist_item_count' => (int) $week->checklist_items_count,
                    'url' => route('admin.programs.weeks.show', [$program, $week]),
                ])
                ->all(),
        ];
    }

    /**
     * Everything the week page renders: the title "Week N: {title}", the week's status, the link back to the program,
     * the sessions notice and the three tabs. Details holds the title and description with the URL and error bag of
     * their form; Activities lists every activity in position order with whether it is active and the link to its page,
     * and holds the URL and error bag of the "Add activity" form; Checklist holds the week's checklist (its first active
     * Gate) with the items on it in position order and, apart, its retired items, or null when the week has none.
     * Reads the activities, the checklist and its items in three queries, plus the notice's one.
     *
     * @return array{title: string, statusLabel: string, statusTone: string, programName: string, programUrl: string, notice: array{sessions: list<array{title: string, dates: string, status_label: string, status_tone: string, url: string}>, has_active: bool, tone: string, message: string}, tabs: list<array{key: string, label: string}>, selectedTab: string, details: array{title: string, description: ?string, update_url: string, error_bag: string}, activities: list<array{title: string, status_label: string, status_tone: string, edit_url: string}>, addActivity: array{store_url: string, error_bag: string}, checklist: ?array{title: string, description: ?string, items: list<array{title: string, description: ?string, type_label: string}>, removed_items: list<array{title: string, description: ?string, type_label: string}>}}
     */
    public function weekData(Program $program, TrainingWeek $week): array
    {
        $week->load([
            'activities' => fn (HasMany $activities): HasMany => $activities->orderBy('position')->orderBy('id'),
            'checklist.criteria' => fn (HasMany $items): HasMany => $items->orderBy('position')->orderBy('id'),
        ]);

        return [
            'title' => "Week {$week->week_number}: {$week->title}",
            'statusLabel' => $week->is_active ? 'Active' : 'Inactive',
            'statusTone' => $week->is_active ? 'success' : 'neutral',
            'programName' => $program->name,
            'programUrl' => route('admin.programs.show', $program),
            'notice' => $this->sessionsNotice($program),
            'tabs' => [
                ['key' => 'details', 'label' => 'Details'],
                ['key' => 'activities', 'label' => 'Activities'],
                ['key' => 'checklist', 'label' => 'Checklist'],
            ],
            'selectedTab' => 'details',
            'details' => [
                'title' => $week->title,
                'description' => $week->description,
                'update_url' => route('admin.programs.weeks.update', [$program, $week]),
                'error_bag' => 'week',
            ],
            'activities' => $week->activities
                ->map(fn (Activity $activity): array => [
                    'title' => $activity->title,
                    'status_label' => $activity->is_active ? 'Active' : 'Inactive',
                    'status_tone' => $activity->is_active ? 'success' : 'neutral',
                    'edit_url' => route('admin.programs.activities.edit', [$program, $week, $activity]),
                ])
                ->all(),
            'addActivity' => [
                'store_url' => route('admin.programs.activities.store', [$program, $week]),
                'error_bag' => 'addActivity',
            ],
            'checklist' => $this->checklistData($week->checklist),
        ];
    }

    /**
     * Everything the activity page renders: the activity's title and status, the week and program it belongs to with
     * the link back to the week's Activities tab, the sessions notice and the three tabs. Details holds the title,
     * description, objectives and instructions with the URL and error bag of their form; Submission fields lists the
     * activity's submission fields in position order and, apart, its removed ones; Scoring lists the scoring dimensions
     * in rubric order with their total.
     * Reads the submission fields in one query, plus the notice's one.
     *
     * @return array{title: string, statusLabel: string, statusTone: string, weekLabel: string, programName: string, weekUrl: string, notice: array{sessions: list<array{title: string, dates: string, status_label: string, status_tone: string, url: string}>, has_active: bool, tone: string, message: string}, tabs: list<array{key: string, label: string}>, selectedTab: string, details: array{title: string, description: ?string, objectives: ?string, instructions: ?string, update_url: string, error_bag: string}, fields: list<array{key: string, label: string, help: ?string, type_label: string, required_label: string, required_tone: string}>, removedFields: list<array{key: string, label: string, help: ?string, type_label: string, required_label: string, required_tone: string}>, scoring: array{dimensions: list<array{key: string, label: string, max: int}>, total: int}}
     */
    public function activityData(Program $program, TrainingWeek $week, Activity $activity): array
    {
        [$fields, $removedFields] = $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->partition(fn (ActivityRequirement $field): bool => ! ($field->configuration['deprecated'] ?? false));

        return [
            'title' => $activity->title,
            'statusLabel' => $activity->is_active ? 'Active' : 'Inactive',
            'statusTone' => $activity->is_active ? 'success' : 'neutral',
            'weekLabel' => "Week {$week->week_number}: {$week->title}",
            'programName' => $program->name,
            'weekUrl' => route('admin.programs.weeks.show', [$program, $week]).'#activities',
            'notice' => $this->sessionsNotice($program),
            'tabs' => [
                ['key' => 'details', 'label' => 'Details'],
                ['key' => 'fields', 'label' => 'Submission fields'],
                ['key' => 'scoring', 'label' => 'Scoring'],
            ],
            'selectedTab' => 'details',
            'details' => [
                'title' => $activity->title,
                'description' => $activity->description,
                'objectives' => $activity->objectives,
                'instructions' => $activity->instructions,
                'update_url' => route('admin.programs.activities.update', [$program, $week, $activity]),
                'error_bag' => 'activity',
            ],
            'fields' => $this->submissionFieldRows($fields),
            'removedFields' => $this->submissionFieldRows($removedFields),
            'scoring' => [
                'dimensions' => $activity->scoringDimensions(),
                'total' => $activity->scoringMaximum(),
            ],
        ];
    }

    /**
     * One row per submission field, in the given order, with its key, label, help, type and whether it is required.
     * The type label is the headline of the stored type until the field types get their own labels.
     *
     * @param  Collection<int, ActivityRequirement>  $fields
     * @return list<array{key: string, label: string, help: ?string, type_label: string, required_label: string, required_tone: string}>
     */
    private function submissionFieldRows(Collection $fields): array
    {
        return $fields
            ->map(function (ActivityRequirement $field): array {
                $configuration = $field->configuration ?? [];
                $isRequired = (bool) ($configuration['required'] ?? $field->is_required);

                return [
                    'key' => (string) ($configuration['key'] ?? ''),
                    'label' => (string) ($configuration['label'] ?? $field->title),
                    'help' => $configuration['help'] ?? $field->description,
                    'type_label' => Str::headline((string) ($configuration['type'] ?? 'text')),
                    'required_label' => $isRequired ? 'Required' : 'Optional',
                    'required_tone' => $isRequired ? 'accent' : 'neutral',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The checklist tab's content: the checklist's title and description, the items on it and its retired items,
     * each in position order with its type label, or null when the week has no checklist.
     *
     * @return ?array{title: string, description: ?string, items: list<array{title: string, description: ?string, type_label: string}>, removed_items: list<array{title: string, description: ?string, type_label: string}>}
     */
    private function checklistData(?TrainingGate $checklist): ?array
    {
        if ($checklist === null) {
            return null;
        }

        [$items, $removedItems] = $checklist->criteria->partition(fn (GateCriterion $item): bool => $item->isOnChecklist());

        return [
            'title' => $checklist->title,
            'description' => $checklist->description,
            'items' => $this->checklistItemRows($items),
            'removed_items' => $this->checklistItemRows($removedItems),
        ];
    }

    /**
     * One row per checklist item, in the given order, with its type label.
     *
     * @param  Collection<int, GateCriterion>  $items
     * @return list<array{title: string, description: ?string, type_label: string}>
     */
    private function checklistItemRows(Collection $items): array
    {
        return $items
            ->map(fn (GateCriterion $item): array => [
                'title' => $item->title,
                'description' => $item->description,
                'type_label' => $item->typeLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * The notice that tells an admin who sees the program's content: the training sessions that use the program,
     * newest start date first, each with its dates, status badge and dashboard link; whether one of them is active
     * (the notice is then a warning rather than information); and the message that counts them by status, or says
     * that no session uses the program yet (one query).
     *
     * @return array{sessions: list<array{title: string, dates: string, status_label: string, status_tone: string, url: string}>, has_active: bool, tone: string, message: string}
     */
    public function sessionsNotice(Program $program): array
    {
        $sessions = $program->trainingSessions()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
        $countsByStatus = $sessions->countBy(fn (TrainingSession $session): string => $session->status()->value);
        $hasActive = $countsByStatus->has(TrainingSessionStatus::Active->value);

        return [
            'sessions' => $sessions
                ->map(fn (TrainingSession $session): array => [
                    'title' => $session->title,
                    'dates' => $session->dateRangeLabel(),
                    'status_label' => $session->status()->label(),
                    'status_tone' => $session->status()->tone(),
                    'url' => route('admin.sessions.show', $session),
                ])
                ->all(),
            'has_active' => $hasActive,
            'tone' => $hasActive ? 'warning' : 'info',
            'message' => $sessions->isEmpty()
                ? 'No training session uses this program yet.'
                : sprintf(
                    'Used by %d training %s (%d active, %d upcoming, %d completed). Changes show to their participants and mentors right away, including in completed sessions.',
                    $sessions->count(),
                    Str::plural('session', $sessions->count()),
                    $countsByStatus->get(TrainingSessionStatus::Active->value, 0),
                    $countsByStatus->get(TrainingSessionStatus::Upcoming->value, 0),
                    $countsByStatus->get(TrainingSessionStatus::Completed->value, 0),
                ),
        ];
    }
}
