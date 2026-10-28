<?php

namespace App\Services;

use App\Enums\ProgramStatus;
use App\Http\Requests\UpdateActivityFieldRequest;
use App\Http\Requests\UpdateActivityScoringRequest;
use App\Http\Requests\UpdateChecklistItemRequest;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CurriculumPageService
{
    /**
     * Everything the curricula list renders: every curriculum by name, each with whether it is offered to join
     * (`is_active`), its schedule status (`ProgramStatus`) and dates, its number of weeks and of participants, and a
     * link to its page (one query).
     *
     * @return array{programs: list<array{name: string, offered_label: string, offered_tone: string, schedule_status_label: string, schedule_status_tone: string, dates: string, weeks_count: int, participants_count: int, url: string}>}
     */
    public function indexData(): array
    {
        return [
            'programs' => Program::query()
                ->withCount(['weeks', 'participants'])
                ->withScheduleBounds()
                ->orderBy('name')
                ->get()
                ->map(function (Program $program): array {
                    $scheduleStatus = $program->status();

                    return [
                        'name' => $program->name,
                        'offered_label' => $program->is_active ? 'Yes' : 'No',
                        'offered_tone' => $program->is_active ? 'success' : 'neutral',
                        'schedule_status_label' => $scheduleStatus->label(),
                        'schedule_status_tone' => $scheduleStatus->tone(),
                        'dates' => $program->dateRangeLabel(),
                        'weeks_count' => (int) $program->weeks_count,
                        'participants_count' => (int) $program->participants_count,
                        'url' => route('admin.programs.show', $program),
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Up to `$limit` curricula for the admin dashboard, the latest first week start first and the unscheduled ones
     * last (both MySQL and SQLite sort nulls last in descending order), then by name, each with its schedule status,
     * dates, number of participants and a link to its page (one query).
     *
     * @return list<array{name: string, status_label: string, status_tone: string, dates: string, participants_count: int, url: string}>
     */
    public function recentProgramRows(int $limit): array
    {
        return Program::query()
            ->withCount('participants')
            ->withScheduleBounds()
            ->orderByDesc('schedule_starts_on')
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Program $program): array {
                $status = $program->status();

                return [
                    'name' => $program->name,
                    'status_label' => $status->label(),
                    'status_tone' => $status->tone(),
                    'dates' => $program->dateRangeLabel(),
                    'participants_count' => (int) $program->participants_count,
                    'url' => route('admin.programs.show', $program),
                ];
            })
            ->all();
    }

    /**
     * The curriculum page's header, Weeks tab and Schedule tab, beside the other tabs of
     * `AdminProgramDashboardService::showData()` (the keys do not overlap): the curriculum's name, description,
     * schedule status (`ProgramStatus`) and dates, the link back to the list, the schedule notice (see
     * scheduleNotice()), the weeks in number order, each with whether it is active, its dates, its number of active
     * activities and of items on its checklist (the required items of its active Gates) and a link to its page, read in
     * one query, and the schedule form (see scheduleData()). Expects the schedule bounds loaded
     * (`Program::loadScheduleBounds()`).
     *
     * @return array{name: string, description: ?string, statusLabel: string, statusTone: string, dates: string, indexUrl: string, notice: array{status: string, tone: string, message: string}, weeks: list<array{number: int, label: string, title: string, status_label: string, status_tone: string, dates: string, active_activity_count: int, checklist_item_count: int, url: string}>, schedule: array{update_url: string, error_bag: string, starts_on: ?string, weeks: list<array{label: string, title: string, dates: string, is_active: bool}>}}
     */
    public function programData(Program $program): array
    {
        $status = $program->status();
        $weeks = $program->weeks()
            ->withCount([
                'activities as active_activities_count' => fn (Builder $activities): Builder => $activities->where('is_active', true),
                'checklistItems as checklist_items_count' => fn (Builder $items): Builder => $items
                    ->where('training_gates.is_active', true)
                    ->where('gate_criteria.is_required', true),
            ])
            ->orderBy('week_number')
            ->get();

        return [
            'name' => $program->name,
            'description' => $program->description,
            'statusLabel' => $status->label(),
            'statusTone' => $status->tone(),
            'dates' => $program->dateRangeLabel(),
            'indexUrl' => route('admin.programs.index'),
            'notice' => $this->scheduleNotice($program),
            'weeks' => $weeks
                ->map(fn (TrainingWeek $week): array => [
                    'number' => $week->week_number,
                    'label' => "Week {$week->week_number}",
                    'title' => $week->title,
                    'status_label' => $week->is_active ? 'Active' : 'Inactive',
                    'status_tone' => $week->is_active ? 'success' : 'neutral',
                    'dates' => $week->dateRangeLabel(),
                    'active_activity_count' => (int) $week->active_activities_count,
                    'checklist_item_count' => (int) $week->checklist_items_count,
                    'url' => route('admin.programs.weeks.show', [$program, $week]),
                ])
                ->all(),
            'schedule' => $this->scheduleData($program, $weeks),
        ];
    }

    /**
     * The schedule form: its URL and error bag, the first day of Week 1 that the current dates follow from (the
     * lowest-numbered dated week's start, moved back one week per week before it), or null while no week has dates,
     * and one row per week in number order, inactive ones included, with its dates.
     *
     * @param  Collection<int, TrainingWeek>  $weeks  the program's weeks in number order
     * @return array{update_url: string, error_bag: string, starts_on: ?string, weeks: list<array{label: string, title: string, dates: string, is_active: bool}>}
     */
    private function scheduleData(Program $program, Collection $weeks): array
    {
        $firstDatedWeek = $weeks->first(fn (TrainingWeek $week): bool => $week->isScheduled());

        return [
            'update_url' => route('admin.programs.schedule.update', $program),
            'error_bag' => 'schedule',
            'starts_on' => $firstDatedWeek?->starts_on->copy()->subWeeks($firstDatedWeek->week_number - 1)->toDateString(),
            'weeks' => $weeks
                ->map(fn (TrainingWeek $week): array => [
                    'label' => "Week {$week->week_number}",
                    'title' => $week->title,
                    'dates' => $week->dateRangeLabel(),
                    'is_active' => $week->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Everything the week page renders: the title "Week N: {title}", the week's status, the link back to the
     * curriculum, the schedule notice (see scheduleNotice()) and the three tabs. Details holds the title, description and dates ("2026-11-09" or null) with
     * the URL and error bag of their form; Activities lists every activity in position order (see activityRows()) with
     * the error bag of their row buttons, and holds the URL and error bag of the "Add activity" form; Checklist holds the week's checklist (its
     * first active Gate) with the URL and error bag of its form, the items on it in position order with theirs, the
     * item types an admin can choose, the URL and error bag of the "Add item" form and, apart, its retired items with
     * their Restore URLs, or null when the week has none (see checklistData()).
     * Reads the activities, their submission fields, the checklist and its items in four queries, plus the curriculum's
     * schedule bounds in one.
     *
     * @return array{title: string, statusLabel: string, statusTone: string, programName: string, programUrl: string, notice: array{status: string, tone: string, message: string}, tabs: list<array{key: string, label: string}>, selectedTab: string, details: array{title: string, description: ?string, starts_on: ?string, ends_on: ?string, update_url: string, error_bag: string}, activities: list<array{title: string, status_label: string, status_tone: string, is_active: bool, field_count: int, edit_url: string, move_url: string, can_move_up: bool, can_move_down: bool, deactivate_url: string, deactivate_confirmation: string, reactivate_url: string}>, activitiesErrorBag: string, addActivity: array{store_url: string, error_bag: string}, checklist: ?array{title: string, description: ?string, update_url: string, error_bag: string, items: list<array{id: int, type: string, has_fixed_type: bool, fixed_type_note: string, title: string, description: ?string, type_label: string, update_url: string, error_bag: string, move_url: string, can_move_up: bool, can_move_down: bool, destroy_url: string, remove_confirmation: string}>, item_types: list<array{value: string, label: string}>, add_item: array{store_url: string, error_bag: string, default_type: string}, removed_items: list<array{title: string, description: ?string, type_label: string, restore_url: string}>}}
     */
    public function weekData(Program $program, TrainingWeek $week): array
    {
        $program->loadScheduleBounds();
        $week->load([
            'activities' => fn (HasMany $activities): HasMany => $activities->orderBy('position')->orderBy('id'),
            'activities.requirements' => fn (HasMany $requirements): HasMany => $requirements
                ->where('requirement_type', 'submission_field')
                ->select(['id', 'activity_id', 'configuration']),
            'checklist.criteria' => fn (HasMany $items): HasMany => $items->orderBy('position')->orderBy('id'),
        ]);

        return [
            'title' => "Week {$week->week_number}: {$week->title}",
            'statusLabel' => $week->is_active ? 'Active' : 'Inactive',
            'statusTone' => $week->is_active ? 'success' : 'neutral',
            'programName' => $program->name,
            'programUrl' => route('admin.programs.show', $program),
            'notice' => $this->scheduleNotice($program),
            'tabs' => [
                ['key' => 'details', 'label' => 'Details'],
                ['key' => 'activities', 'label' => 'Activities'],
                ['key' => 'checklist', 'label' => 'Checklist'],
            ],
            'selectedTab' => 'details',
            'details' => [
                'title' => $week->title,
                'description' => $week->description,
                'starts_on' => $week->starts_on?->toDateString(),
                'ends_on' => $week->ends_on?->toDateString(),
                'update_url' => route('admin.programs.weeks.update', [$program, $week]),
                'error_bag' => 'week',
            ],
            'activities' => $this->activityRows($program, $week),
            'activitiesErrorBag' => 'activities',
            'addActivity' => [
                'store_url' => route('admin.programs.activities.store', [$program, $week]),
                'error_bag' => 'addActivity',
            ],
            'checklist' => $this->checklistData($program, $week, $week->checklist),
        ];
    }

    /**
     * Everything the activity page renders: the activity's title and status, the week and program it belongs to with
     * the link back to the week's Activities tab, the schedule notice (see scheduleNotice()) and the three tabs. Details holds the title,
     * description, objectives and instructions with the URL and error bag of their form; Submission fields lists the
     * activity's submission fields in position order, each with the URLs and error bag of its forms (see
     * fieldFormRows()), the field types an admin can choose, the URL and error bag of the "Add field" form and, apart,
     * the removed fields with their Restore URLs; Scoring holds the scoring dimensions in rubric order with their total
     * and either the rows of their form or, once the activity has a score, why they can no longer change (see
     * scoringData()). Reads the submission fields in one query and whether a score exists in another, plus the
     * curriculum's schedule bounds in one.
     *
     * @return array{title: string, statusLabel: string, statusTone: string, weekLabel: string, programName: string, weekUrl: string, notice: array{status: string, tone: string, message: string}, tabs: list<array{key: string, label: string}>, selectedTab: string, details: array{title: string, description: ?string, objectives: ?string, instructions: ?string, update_url: string, error_bag: string}, fields: list<array{id: int, key: string, label: string, help: ?string, type: string, type_label: string, has_fixed_type: bool, fixed_type_note: string, is_required: bool, required_label: string, required_tone: string, update_url: string, error_bag: string, move_url: string, can_move_up: bool, can_move_down: bool, destroy_url: string, remove_confirmation: string}>, fieldTypes: list<array{value: string, label: string}>, addField: array{store_url: string, error_bag: string}, removedFields: list<array{key: string, label: string, help: ?string, type: string, type_label: string, has_fixed_type: bool, fixed_type_note: string, is_required: bool, required_label: string, required_tone: string, restore_url: string}>, scoring: array{dimensions: list<array{key: string, label: string, max: int}>, total: int, is_locked: bool, locked_message: string, rows: list<array{index: int, key: string, label: ?string, max: ?int}>, required_total: int, label_max_length: int, update_url: string, error_bag: string}}
     */
    public function activityData(Program $program, TrainingWeek $week, Activity $activity): array
    {
        $program->loadScheduleBounds();
        [$fields, $removedFields] = $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->partition(fn (ActivityRequirement $field): bool => ! $field->isRemovedField());

        return [
            'title' => $activity->title,
            'statusLabel' => $activity->is_active ? 'Active' : 'Inactive',
            'statusTone' => $activity->is_active ? 'success' : 'neutral',
            'weekLabel' => "Week {$week->week_number}: {$week->title}",
            'programName' => $program->name,
            'weekUrl' => route('admin.programs.weeks.show', [$program, $week]).'#activities',
            'notice' => $this->scheduleNotice($program),
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
            'fields' => $this->fieldFormRows($program, $week, $activity, $fields),
            'fieldTypes' => collect(ActivityRequirement::FIELD_TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'addField' => [
                'store_url' => route('admin.programs.fields.store', [$program, $week, $activity]),
                'error_bag' => 'addField',
            ],
            'removedFields' => $removedFields
                ->map(fn (ActivityRequirement $field): array => [
                    ...$this->submissionFieldRow($field),
                    'restore_url' => route('admin.programs.fields.restore', [$program, $week, $activity, $field]),
                ])
                ->values()
                ->all(),
            'scoring' => $this->scoringData($program, $week, $activity),
        ];
    }

    /**
     * The Scoring tab's content: the activity's scoring dimensions in rubric order with their total, whether they are
     * locked because the activity has a score (one query) and why, and the form: its URL and error bag, the total the
     * maxima must add up to, the longest label, and one row per possible dimension (nine), filled with the current
     * dimensions in order and blank after them. A locked tab has no rows.
     *
     * @return array{dimensions: list<array{key: string, label: string, max: int}>, total: int, is_locked: bool, locked_message: string, rows: list<array{index: int, key: string, label: ?string, max: ?int}>, required_total: int, label_max_length: int, update_url: string, error_bag: string}
     */
    private function scoringData(Program $program, TrainingWeek $week, Activity $activity): array
    {
        $dimensions = $activity->scoringDimensions();
        $isLocked = $activity->hasScores();

        return [
            'dimensions' => $dimensions,
            'total' => $activity->scoringMaximum(),
            'is_locked' => $isLocked,
            'locked_message' => UpdateActivityScoringRequest::LOCKED_MESSAGE,
            'rows' => $isLocked ? [] : array_map(fn (int $index): array => [
                'index' => $index,
                'key' => 'D'.($index + 1),
                'label' => $dimensions[$index]['label'] ?? null,
                'max' => $dimensions[$index]['max'] ?? null,
            ], range(0, Activity::MAX_SCORING_DIMENSIONS - 1)),
            'required_total' => Activity::SCORING_TOTAL,
            'label_max_length' => UpdateActivityScoringRequest::LABEL_MAX_LENGTH,
            'update_url' => route('admin.programs.scoring.update', [$program, $week, $activity]),
            'error_bag' => 'scoring',
        ];
    }

    /**
     * One row per activity of the week, in position order, active or not: its title, status and number of submission
     * fields (removed ones not counted), the link to its page, and the URLs of its row buttons. The first row cannot
     * move up and the last cannot move down. Reads the week's eager-loaded activities and their submission fields.
     *
     * @return list<array{title: string, status_label: string, status_tone: string, is_active: bool, field_count: int, edit_url: string, move_url: string, can_move_up: bool, can_move_down: bool, deactivate_url: string, deactivate_confirmation: string, reactivate_url: string}>
     */
    private function activityRows(Program $program, TrainingWeek $week): array
    {
        $lastIndex = $week->activities->count() - 1;

        return $week->activities
            ->values()
            ->map(fn (Activity $activity, int $index): array => [
                'title' => $activity->title,
                'status_label' => $activity->is_active ? 'Active' : 'Inactive',
                'status_tone' => $activity->is_active ? 'success' : 'neutral',
                'is_active' => $activity->is_active,
                'field_count' => $activity->requirements->reject(fn (ActivityRequirement $field): bool => $field->isRemovedField())->count(),
                'edit_url' => route('admin.programs.activities.edit', [$program, $week, $activity]),
                'move_url' => route('admin.programs.activities.move', [$program, $week, $activity]),
                'can_move_up' => $index > 0,
                'can_move_down' => $index < $lastIndex,
                'deactivate_url' => route('admin.programs.activities.deactivate', [$program, $week, $activity]),
                'deactivate_confirmation' => "Deactivate {$activity->title}? Participants keep their submissions, but the activity leaves the week.",
                'reactivate_url' => route('admin.programs.activities.reactivate', [$program, $week, $activity]),
            ])
            ->all();
    }

    /**
     * One row per active submission field, in the given order (see submissionFieldRow()), with its id, the URL and error
     * bag of its form, and the URLs of its Move and Remove buttons with the Remove confirmation. The first row cannot
     * move up and the last cannot move down.
     *
     * @param  Collection<int, ActivityRequirement>  $fields
     * @return list<array{id: int, key: string, label: string, help: ?string, type: string, type_label: string, has_fixed_type: bool, fixed_type_note: string, is_required: bool, required_label: string, required_tone: string, update_url: string, error_bag: string, move_url: string, can_move_up: bool, can_move_down: bool, destroy_url: string, remove_confirmation: string}>
     */
    private function fieldFormRows(Program $program, TrainingWeek $week, Activity $activity, Collection $fields): array
    {
        $lastIndex = $fields->count() - 1;

        return $fields
            ->values()
            ->map(function (ActivityRequirement $field, int $index) use ($program, $week, $activity, $lastIndex): array {
                $row = $this->submissionFieldRow($field);

                return [
                    'id' => $field->id,
                    ...$row,
                    'update_url' => route('admin.programs.fields.update', [$program, $week, $activity, $field]),
                    'error_bag' => UpdateActivityFieldRequest::errorBagFor($field),
                    'move_url' => route('admin.programs.fields.move', [$program, $week, $activity, $field]),
                    'can_move_up' => $index > 0,
                    'can_move_down' => $index < $lastIndex,
                    'destroy_url' => route('admin.programs.fields.destroy', [$program, $week, $activity, $field]),
                    'remove_confirmation' => "Remove {$row['label']}? Participants' answers to it are kept, but the field leaves the form.",
                ];
            })
            ->all();
    }

    /**
     * A submission field's key, label, help, type with its label (`ActivityRequirement::FIELD_TYPES`), whether its type
     * is not offered (such as `select` or `date`) and so cannot be chosen, with the note shown in place of the type
     * select, and whether it is required.
     *
     * @return array{key: string, label: string, help: ?string, type: string, type_label: string, has_fixed_type: bool, fixed_type_note: string, is_required: bool, required_label: string, required_tone: string}
     */
    private function submissionFieldRow(ActivityRequirement $field): array
    {
        $settings = $field->fieldSettings();

        return [
            'key' => (string) ($field->configuration['key'] ?? ''),
            'label' => $settings['label'],
            'help' => $settings['help'],
            'type' => $settings['type'],
            'type_label' => $field->fieldTypeLabel(),
            'has_fixed_type' => $field->hasFixedFieldType(),
            'fixed_type_note' => $settings['type'] === 'select'
                ? "{$field->fieldTypeLabel()}. Its options are kept and cannot change here."
                : "{$field->fieldTypeLabel()}. This type cannot change here.",
            'is_required' => $settings['required'],
            'required_label' => $settings['required'] ? 'Required' : 'Optional',
            'required_tone' => $settings['required'] ? 'accent' : 'neutral',
        ];
    }

    /**
     * The checklist tab's content: the checklist's title and description with the URL and error bag of their form, the
     * items on it in position order, each with its id, its type and whether that type is not offered (and so cannot
     * change, with the note shown in place of the type select), the URL and error bag of its form and the URL of its
     * Move buttons (the first item cannot move up and the last cannot move down) and of its Remove button with the
     * confirmation that names it, the item types an admin can choose (`GateCriterion::TYPE_LABELS`), the URL, error bag
     * and preselected type of the "Add item" form, and its retired items in position order with their Restore URLs, or
     * null when the week has no checklist.
     *
     * @return ?array{title: string, description: ?string, update_url: string, error_bag: string, items: list<array{id: int, type: string, has_fixed_type: bool, fixed_type_note: string, title: string, description: ?string, type_label: string, update_url: string, error_bag: string, move_url: string, can_move_up: bool, can_move_down: bool, destroy_url: string, remove_confirmation: string}>, item_types: list<array{value: string, label: string}>, add_item: array{store_url: string, error_bag: string, default_type: string}, removed_items: list<array{title: string, description: ?string, type_label: string, restore_url: string}>}
     */
    private function checklistData(Program $program, TrainingWeek $week, ?TrainingGate $checklist): ?array
    {
        if ($checklist === null) {
            return null;
        }

        [$items, $removedItems] = $checklist->criteria->partition(fn (GateCriterion $item): bool => $item->isOnChecklist());
        $lastIndex = $items->count() - 1;

        return [
            'title' => $checklist->title,
            'description' => $checklist->description,
            'update_url' => route('admin.programs.checklist.update', [$program, $week]),
            'error_bag' => 'checklist',
            'items' => $items
                ->values()
                ->map(fn (GateCriterion $item, int $index): array => [
                    'id' => $item->id,
                    'type' => $item->criterion_type,
                    'has_fixed_type' => $item->hasFixedType(),
                    'fixed_type_note' => "{$item->typeLabel()}. This type cannot change here.",
                    ...$this->checklistItemRow($item),
                    'update_url' => route('admin.programs.checklist-items.update', [$program, $week, $item]),
                    'error_bag' => UpdateChecklistItemRequest::errorBagFor($item),
                    'move_url' => route('admin.programs.checklist-items.move', [$program, $week, $item]),
                    'can_move_up' => $index > 0,
                    'can_move_down' => $index < $lastIndex,
                    'destroy_url' => route('admin.programs.checklist-items.destroy', [$program, $week, $item]),
                    'remove_confirmation' => "Remove {$item->title}? Mentors' earlier ticks are kept; the item leaves the checklist.",
                ])
                ->all(),
            'item_types' => collect(GateCriterion::TYPE_LABELS)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'add_item' => [
                'store_url' => route('admin.programs.checklist-items.store', [$program, $week]),
                'error_bag' => 'addChecklistItem',
                'default_type' => GateCriterion::TYPE_DELIVERABLE,
            ],
            'removed_items' => $removedItems
                ->map(fn (GateCriterion $item): array => [
                    ...$this->checklistItemRow($item),
                    'restore_url' => route('admin.programs.checklist-items.restore', [$program, $week, $item]),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A checklist item's title, description and type label.
     *
     * @return array{title: string, description: ?string, type_label: string}
     */
    private function checklistItemRow(GateCriterion $item): array
    {
        return [
            'title' => $item->title,
            'description' => $item->description,
            'type_label' => $item->typeLabel(),
        ];
    }

    /**
     * The notice above the curriculum's pages that tells an admin who sees a content change: while the curriculum is
     * active its participants and mentors see it right away (a warning); otherwise when they can open its weeks, or
     * that nobody can while no active week has dates. Its `status` is the `ProgramStatus` value. Reads the schedule
     * bounds the caller loaded.
     *
     * @return array{status: string, tone: string, message: string}
     */
    private function scheduleNotice(Program $program): array
    {
        $status = $program->status();
        $dates = $program->dateRangeLabel();

        return [
            'status' => $status->value,
            'tone' => $status === ProgramStatus::Active ? 'warning' : 'info',
            'message' => match ($status) {
                ProgramStatus::Unscheduled => 'Not scheduled: participants cannot open any week yet.',
                ProgramStatus::Upcoming => "Upcoming {$dates}: participants can open the first week on {$program->scheduleStartsOn()->format('j M Y')}.",
                ProgramStatus::Active => "Active {$dates}: changes show to its participants and mentors right away.",
                ProgramStatus::Completed => "Completed {$dates}: changes still show to its participants and mentors when they look back.",
            },
        ];
    }
}
