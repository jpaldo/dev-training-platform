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

    public function __construct(private AdminAuditService $audit) {}

    /**
     * Give the week a new title and description and record `curriculum_week_updated` with the changed values before
     * and after. A description left out of the details is kept. The week's number, position and whether it is active
     * never change here.
     *
     * @param  array{title: string, description?: ?string}  $details
     */
    public function updateWeek(TrainingWeek $week, array $details, User $admin): TrainingWeek
    {
        $week->fill(Arr::only($details, ['title', 'description']));

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
     * The model's unsaved attribute changes, each with its stored and its new value.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(Model $model): array
    {
        return collect($model->getDirty())
            ->mapWithKeys(fn (mixed $value, string $attribute): array => [
                $attribute => ['from' => $model->getOriginal($attribute), 'to' => $value],
            ])
            ->all();
    }
}
