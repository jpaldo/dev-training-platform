<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin edits to an activity's submission fields on the Curriculum pages. A field's key never changes, so participants'
 * answers stay with it. Every edit is saved in a transaction with its `AdminAuditService` row; an edit that changes
 * nothing is neither saved nor recorded.
 */
class ActivityFieldService
{
    /**
     * The longest key a new field gets, its `_2`, `_3`… suffix included.
     */
    private const MAX_KEY_LENGTH = 40;

    public function __construct(private AdminAuditService $audit, private CurriculumOrderService $order) {}

    /**
     * Add a submission field with the given settings to the end of the activity, after every requirement including
     * removed fields, and record `curriculum_field_added`. Its key comes from its label (see keyFor()) and never
     * changes. The activity row is locked while the key and position are chosen, so two adds at once cannot take the
     * same key or position.
     *
     * @param  array{label: string, help: ?string, type: string, required: bool}  $settings
     */
    public function add(Activity $activity, array $settings, User $admin): ActivityRequirement
    {
        return DB::transaction(function () use ($activity, $settings, $admin): ActivityRequirement {
            Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();

            $field = $activity->requirements()->create([
                'requirement_type' => 'submission_field',
                'title' => $settings['label'],
                'description' => $settings['help'],
                'is_required' => $settings['required'],
                'position' => (int) $activity->requirements()->max('position') + 1,
                'configuration' => [
                    'key' => $this->keyFor($activity, $settings['label']),
                    'label' => $settings['label'],
                    'type' => $settings['type'],
                    'required' => $settings['required'],
                    'help' => $settings['help'],
                ],
            ]);

            $this->audit->record($admin, 'curriculum_field_added', metadata: [
                ...$this->ids($activity, $field),
                'key' => $field->configuration['key'],
                'label' => $settings['label'],
                'type' => $settings['type'],
                'required' => $settings['required'],
                'position' => $field->position,
            ]);

            return $field;
        });
    }

    /**
     * Give the submission field a new label, help, type and "required", and record `curriculum_field_updated` with the
     * changed settings before and after. The settings are merged into the field's `configuration`, so its key, a select
     * field's options and every other stored key are kept, and mirrored in its `title`, `description` and
     * `is_required` columns. Help or "required" left out of the settings is kept.
     *
     * @param  array{label: string, help?: ?string, type: string, required?: bool}  $settings
     */
    public function update(ActivityRequirement $field, array $settings, User $admin): ActivityRequirement
    {
        $current = $field->fieldSettings();
        $updated = array_replace($current, array_intersect_key($settings, $current));
        $changes = collect($updated)
            ->filter(fn (mixed $value, string $setting): bool => $value !== $current[$setting])
            ->map(fn (mixed $value, string $setting): array => ['from' => $current[$setting], 'to' => $value])
            ->all();

        if ($changes === []) {
            return $field;
        }

        return DB::transaction(function () use ($field, $updated, $changes, $admin): ActivityRequirement {
            $field->update([
                'configuration' => array_replace($field->configuration ?? [], $updated),
                'title' => $updated['label'],
                'description' => $updated['help'],
                'is_required' => $updated['required'],
            ]);

            $this->audit->record($admin, 'curriculum_field_updated', metadata: [
                ...$this->ids($field->activity, $field),
                'changes' => $changes,
            ]);

            return $field;
        });
    }

    /**
     * Move the submission field one place up or down among its activity's fields still on the form, by swapping
     * positions with its neighbour, and record `curriculum_field_moved` with the direction and the position before and
     * after. Removed fields keep their positions. The activity row is locked while the positions are read and swapped,
     * so two moves at once cannot collide. Returns whether it moved: the first field moved up or the last moved down
     * stays put and nothing is recorded.
     *
     * @param  'up'|'down'  $direction
     */
    public function move(ActivityRequirement $field, string $direction, User $admin): bool
    {
        return DB::transaction(function () use ($field, $direction, $admin): bool {
            Activity::query()->whereKey($field->activity_id)->lockForUpdate()->firstOrFail();
            $field->refresh();
            $from = $field->position;
            $fieldsOnTheForm = $field->activity->requirements()
                ->where('requirement_type', 'submission_field')
                ->get()
                ->reject(fn (ActivityRequirement $sibling): bool => $sibling->isRemovedField())
                ->modelKeys();

            if (! $this->order->move($field, ActivityRequirement::query()->whereKey($fieldsOnTheForm), $direction)) {
                return false;
            }

            $this->audit->record($admin, 'curriculum_field_moved', metadata: [
                ...$this->ids($field->activity, $field),
                'direction' => $direction,
                'changes' => ['position' => ['from' => $from, 'to' => $field->position]],
            ]);

            return true;
        });
    }

    /**
     * Take the submission field off the participant form for every session of the program and record
     * `curriculum_field_removed`. Like the seeder used to, it is marked `deprecated` and made optional; it keeps its
     * key, position and every answer given to it, which the submission and review pages still show. A removed field is
     * left as it is and nothing is recorded.
     */
    public function retire(ActivityRequirement $field, User $admin): ActivityRequirement
    {
        if ($field->isRemovedField()) {
            return $field;
        }

        $changes = ['removed' => ['from' => false, 'to' => true]];

        if ($field->fieldSettings()['required']) {
            $changes['required'] = ['from' => true, 'to' => false];
        }

        return DB::transaction(function () use ($field, $changes, $admin): ActivityRequirement {
            $field->update([
                'configuration' => array_replace($field->configuration ?? [], ['required' => false, 'deprecated' => true]),
                'is_required' => false,
            ]);

            $this->audit->record($admin, 'curriculum_field_removed', metadata: [
                ...$this->ids($field->activity, $field),
                'changes' => $changes,
            ]);

            return $field;
        });
    }

    /**
     * Put a removed submission field back on the participant form at the position it kept, still optional, and record
     * `curriculum_field_restored`. Answers given to it before it was removed show in the form again. A field on the
     * form is left as it is and nothing is recorded.
     */
    public function restore(ActivityRequirement $field, User $admin): ActivityRequirement
    {
        if (! $field->isRemovedField()) {
            return $field;
        }

        return DB::transaction(function () use ($field, $admin): ActivityRequirement {
            $configuration = $field->configuration ?? [];
            unset($configuration['deprecated']);
            $field->update(['configuration' => $configuration]);

            $this->audit->record($admin, 'curriculum_field_restored', metadata: [
                ...$this->ids($field->activity, $field),
                'changes' => ['removed' => ['from' => true, 'to' => false]],
            ]);

            return $field;
        });
    }

    /**
     * The key of a new field with the given label: the label in ASCII snake case, keeping only `a-z`, `0-9` and `_`
     * and at most 40 characters, or `field` when nothing is left. A key another submission field of the activity
     * already has (removed fields included) gets the first free suffix `_2`, `_3`, and so on.
     */
    public function keyFor(Activity $activity, string $label): string
    {
        $base = Str::of(Str::ascii($label))
            ->lower()
            ->snake()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->value();
        $base = $base === '' ? 'field' : $base;
        $takenKeys = $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->get(['configuration'])
            ->map(fn (ActivityRequirement $field): string => (string) ($field->configuration['key'] ?? ''))
            ->all();

        for ($number = 1; ; $number++) {
            $suffix = $number === 1 ? '' : "_{$number}";
            $key = rtrim(substr($base, 0, self::MAX_KEY_LENGTH - strlen($suffix)), '_').$suffix;

            if (! in_array($key, $takenKeys, true)) {
                return $key;
            }
        }
    }

    /**
     * The ids every field audit row holds: the program, week, activity and field.
     *
     * @return array{program_id: int, training_week_id: int, activity_id: int, activity_requirement_id: int}
     */
    private function ids(Activity $activity, ActivityRequirement $field): array
    {
        return [
            'program_id' => $activity->week->program_id,
            'training_week_id' => $activity->training_week_id,
            'activity_id' => $activity->id,
            'activity_requirement_id' => $field->id,
        ];
    }
}
