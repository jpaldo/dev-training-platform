<?php

namespace App\Services;

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
