<?php

namespace App\Services;

use App\Models\GateCriterion;
use App\Models\TrainingGate;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Admin edits to a week's checklist (its first active Gate) and its items on the Curriculum pages. Items keep their ids,
 * so mentors' ticks stay with them. Every edit is saved in a transaction with its `AdminAuditService` row; an edit that
 * changes nothing is neither saved nor recorded.
 */
class ChecklistService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * Give the checklist a new title and description and record `curriculum_checklist_updated` with the changed values
     * before and after. A description left out of the details is kept.
     *
     * @param  array{title: string, description?: ?string}  $details
     */
    public function updateChecklist(TrainingGate $checklist, array $details, User $admin): TrainingGate
    {
        $checklist->fill(Arr::only($details, ['title', 'description']));

        if (! $checklist->isDirty()) {
            return $checklist;
        }

        $changes = $this->changes($checklist->getDirty(), $checklist->getOriginal());

        return DB::transaction(function () use ($checklist, $changes, $admin): TrainingGate {
            $checklist->save();

            $this->audit->record($admin, 'curriculum_checklist_updated', metadata: [
                ...$this->ids($checklist),
                'changes' => $changes,
            ]);

            return $checklist;
        });
    }

    /**
     * Give the checklist item a new type, title and description and record `curriculum_checklist_item_updated` with the
     * changed values before and after (the type under `type`). Its id, position, place on the checklist and
     * `configuration` are untouched, so mentors' ticks and stored keys such as `expected_files` stay with it. A
     * description left out of the details is kept.
     *
     * @param  array{type: string, title: string, description?: ?string}  $details
     */
    public function updateItem(GateCriterion $item, array $details, User $admin): GateCriterion
    {
        $item->fill([
            'criterion_type' => $details['type'],
            ...Arr::only($details, ['title', 'description']),
        ]);

        if (! $item->isDirty()) {
            return $item;
        }

        $changes = $this->changes($item->getDirty(), $item->getOriginal());

        if (array_key_exists('criterion_type', $changes)) {
            $changes = ['type' => $changes['criterion_type'], ...Arr::except($changes, 'criterion_type')];
        }

        return DB::transaction(function () use ($item, $changes, $admin): GateCriterion {
            $item->save();

            $this->audit->record($admin, 'curriculum_checklist_item_updated', metadata: [
                ...$this->ids($item->gate),
                'gate_criterion_id' => $item->id,
                'changes' => $changes,
            ]);

            return $item;
        });
    }

    /**
     * The ids every checklist audit row holds: the program, week and checklist (Gate).
     *
     * @return array{program_id: int, training_week_id: int, training_gate_id: int}
     */
    private function ids(TrainingGate $checklist): array
    {
        return [
            'program_id' => $checklist->week->program_id,
            'training_week_id' => $checklist->training_week_id,
            'training_gate_id' => $checklist->id,
        ];
    }

    /**
     * Each changed attribute with its value before and after.
     *
     * @param  array<string, mixed>  $dirty
     * @param  array<string, mixed>  $original
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(array $dirty, array $original): array
    {
        return collect($dirty)
            ->mapWithKeys(fn (mixed $value, string $attribute): array => [
                $attribute => ['from' => $original[$attribute] ?? null, 'to' => $value],
            ])
            ->all();
    }
}
