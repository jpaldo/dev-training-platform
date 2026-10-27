<?php

namespace App\Services;

use App\Models\ActivityRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin edits to an activity's submission fields on the Curriculum pages. A field's key never changes, so participants'
 * answers stay with it. Every edit is saved in a transaction with its `AdminAuditService` row; an edit that changes
 * nothing is neither saved nor recorded.
 */
class ActivityFieldService
{
    public function __construct(private AdminAuditService $audit) {}

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
                'program_id' => $field->activity->week->program_id,
                'training_week_id' => $field->activity->training_week_id,
                'activity_id' => $field->activity_id,
                'activity_requirement_id' => $field->id,
                'changes' => $changes,
            ]);

            return $field;
        });
    }
}
