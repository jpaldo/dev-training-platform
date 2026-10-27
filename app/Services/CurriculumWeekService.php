<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Admin edits to a program week's content on the Curriculum pages. Every edit is saved in a transaction with its
 * `AdminAuditService` row; an edit that changes nothing is neither saved nor recorded.
 */
class CurriculumWeekService
{
    /**
     * The optional "Notes" submission field every new activity gets, as `L4TrainingProgramSeeder` gives it to every
     * seeded activity.
     *
     * @var array{key: string, label: string, type: string, required: bool, help: string}
     */
    private const NOTES_FIELD = [
        'key' => 'notes',
        'label' => 'Notes',
        'type' => 'textarea',
        'required' => false,
        'help' => 'Anything your mentor should know about this deliverable.',
    ];

    public function __construct(private AdminAuditService $audit, private CurriculumOrderService $order) {}

    /**
     * Give the week a new title, description and dates and record `curriculum_week_updated` with the changed values
     * before and after (dates as "2026-11-09"). A description left out of the details is kept, and so are the dates
     * when both are left out; given one, the two are set together, so a null pair unschedules the week. The week's
     * number, position and whether it is active never change here.
     *
     * @param  array{title: string, description?: ?string, starts_on?: ?string, ends_on?: ?string}  $details
     */
    public function updateWeek(TrainingWeek $week, array $details, User $admin): TrainingWeek
    {
        $week->fill(Arr::only($details, ['title', 'description']));

        if (Arr::hasAny($details, ['starts_on', 'ends_on'])) {
            $week->fill(['starts_on' => $details['starts_on'] ?? null, 'ends_on' => $details['ends_on'] ?? null]);
        }

        if (! $week->isDirty()) {
            return $week;
        }

        $changes = $this->changes($week);

        return DB::transaction(function () use ($week, $changes, $admin): TrainingWeek {
            $week->save();

            $this->audit->record($admin, 'curriculum_week_updated', metadata: [
                'program_id' => $week->program_id,
                'training_week_id' => $week->id,
                'changes' => $changes,
            ]);

            return $week;
        });
    }

    /**
     * Add an activity with the given text to the end of the week, after every activity including inactive ones, and
     * record `curriculum_activity_added`. The new activity is active, reviewed by a mentor, scored on the default
     * rubric (`Activity::DEFAULT_SCORING_DIMENSIONS`) and has one submission field, the optional "Notes". The week row
     * is locked while the position is chosen, so two adds at once cannot take the same position.
     *
     * @param  array{title: string, description?: ?string, objectives?: ?string, instructions?: ?string}  $details
     */
    public function addActivity(TrainingWeek $week, array $details, User $admin): Activity
    {
        return DB::transaction(function () use ($week, $details, $admin): Activity {
            TrainingWeek::query()->whereKey($week->id)->lockForUpdate()->firstOrFail();

            $activity = $week->activities()->create([
                ...Arr::only($details, ['title', 'description', 'objectives', 'instructions']),
                'position' => (int) $week->activities()->max('position') + 1,
                'configuration' => ['scoring_dimensions' => Activity::DEFAULT_SCORING_DIMENSIONS],
                'requires_coach_review' => true,
                'is_active' => true,
            ]);

            $activity->requirements()->create([
                'requirement_type' => 'submission_field',
                'title' => self::NOTES_FIELD['label'],
                'description' => self::NOTES_FIELD['help'],
                'is_required' => self::NOTES_FIELD['required'],
                'position' => 1,
                'configuration' => self::NOTES_FIELD,
            ]);

            $this->audit->record($admin, 'curriculum_activity_added', metadata: [
                'program_id' => $week->program_id,
                'training_week_id' => $week->id,
                'activity_id' => $activity->id,
                'title' => $activity->title,
                'position' => $activity->position,
            ]);

            return $activity;
        });
    }

    /**
     * Give the activity a new title, description, objectives and instructions and record `curriculum_activity_updated`
     * with the changed values before and after. Text left out of the details is kept. The activity's position, whether
     * it is active and its `configuration` (scoring dimensions, expected files) never change here.
     *
     * @param  array{title: string, description?: ?string, objectives?: ?string, instructions?: ?string}  $details
     */
    public function updateActivity(Activity $activity, array $details, User $admin): Activity
    {
        $activity->fill(Arr::only($details, ['title', 'description', 'objectives', 'instructions']));

        if (! $activity->isDirty()) {
            return $activity;
        }

        $changes = $this->changes($activity);

        return DB::transaction(function () use ($activity, $changes, $admin): Activity {
            $activity->save();

            $this->audit->record($admin, 'curriculum_activity_updated', metadata: [
                'program_id' => $activity->week->program_id,
                'training_week_id' => $activity->training_week_id,
                'activity_id' => $activity->id,
                'changes' => $changes,
            ]);

            return $activity;
        });
    }

    /**
     * Move the activity one place up or down among every activity of its week, active or not, by swapping positions
     * with its neighbour, and record `curriculum_activity_moved` with the direction and the position before and after.
     * The week row is locked while the positions are read and swapped, so two moves at once cannot collide. Returns
     * whether it moved: the first activity moved up or the last moved down stays put and nothing is recorded.
     *
     * @param  'up'|'down'  $direction
     */
    public function moveActivity(Activity $activity, string $direction, User $admin): bool
    {
        return DB::transaction(function () use ($activity, $direction, $admin): bool {
            TrainingWeek::query()->whereKey($activity->training_week_id)->lockForUpdate()->firstOrFail();
            $activity->refresh();
            $from = $activity->position;

            if (! $this->order->move($activity, Activity::query()->where('training_week_id', $activity->training_week_id), $direction)) {
                return false;
            }

            $this->audit->record($admin, 'curriculum_activity_moved', metadata: [
                'program_id' => $activity->week->program_id,
                'training_week_id' => $activity->training_week_id,
                'activity_id' => $activity->id,
                'direction' => $direction,
                'changes' => ['position' => ['from' => $from, 'to' => $activity->position]],
            ]);

            return true;
        });
    }

    /**
     * Take the activity out of its week for every session of the program and record `curriculum_activity_deactivated`.
     * It keeps its position, submissions and scores. An inactive activity is left as it is and nothing is recorded.
     */
    public function deactivateActivity(Activity $activity, User $admin): Activity
    {
        return $this->setActivityActive($activity, false, 'curriculum_activity_deactivated', $admin);
    }

    /**
     * Put the activity back in its week, at the position it kept, and record `curriculum_activity_reactivated`. An
     * active activity is left as it is and nothing is recorded.
     */
    public function reactivateActivity(Activity $activity, User $admin): Activity
    {
        return $this->setActivityActive($activity, true, 'curriculum_activity_reactivated', $admin);
    }

    /**
     * Replace the activity's scoring dimensions with the given rows, keyed D1 to Dn in row order, and record
     * `curriculum_scoring_updated` with the dimensions before and after. Only `configuration.scoring_dimensions`
     * changes; the configuration's other keys (such as `expected_files`) are kept. When the rows match the current
     * dimensions, keys included, nothing is saved or recorded. The caller makes sure the activity has no score yet.
     *
     * @param  list<array{label: string, max: int}>  $dimensions
     */
    public function updateScoringDimensions(Activity $activity, array $dimensions, User $admin): Activity
    {
        $before = $activity->scoringDimensions();
        $after = collect($dimensions)
            ->values()
            ->map(fn (array $dimension, int $index): array => [
                'key' => 'D'.($index + 1),
                'label' => $dimension['label'],
                'max' => $dimension['max'],
            ])
            ->all();

        if ($after === $before) {
            return $activity;
        }

        return DB::transaction(function () use ($activity, $before, $after, $admin): Activity {
            $activity->configuration = array_replace($activity->configuration ?? [], ['scoring_dimensions' => $after]);
            $activity->save();

            $this->audit->record($admin, 'curriculum_scoring_updated', metadata: [
                'program_id' => $activity->week->program_id,
                'training_week_id' => $activity->training_week_id,
                'activity_id' => $activity->id,
                'changes' => ['scoring_dimensions' => ['from' => $before, 'to' => $after]],
            ]);

            return $activity;
        });
    }

    /**
     * Save whether the activity is active and record the given audit action with the change, unless it already is.
     */
    private function setActivityActive(Activity $activity, bool $isActive, string $action, User $admin): Activity
    {
        $activity->is_active = $isActive;

        if (! $activity->isDirty()) {
            return $activity;
        }

        $changes = $this->changes($activity);

        return DB::transaction(function () use ($activity, $action, $changes, $admin): Activity {
            $activity->save();

            $this->audit->record($admin, $action, metadata: [
                'program_id' => $activity->week->program_id,
                'training_week_id' => $activity->training_week_id,
                'activity_id' => $activity->id,
                'changes' => $changes,
            ]);

            return $activity;
        });
    }

    /**
     * The model's unsaved attribute changes, each with its stored and its new value. A `date` attribute's values are
     * days ("2026-11-09") or null.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(Model $model): array
    {
        return collect($model->getDirty())
            ->mapWithKeys(fn (mixed $value, string $attribute): array => [
                $attribute => $model->hasCast($attribute, ['date'])
                    ? ['from' => $model->getOriginal($attribute)?->toDateString(), 'to' => $model->getAttribute($attribute)?->toDateString()]
                    : ['from' => $model->getOriginal($attribute), 'to' => $value],
            ])
            ->all();
    }
}
