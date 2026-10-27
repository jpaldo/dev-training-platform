<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveCurriculumItemRequest;
use App\Http\Requests\RemoveChecklistItemRequest;
use App\Http\Requests\RestoreChecklistItemRequest;
use App\Http\Requests\StoreChecklistItemRequest;
use App\Http\Requests\UpdateChecklistItemRequest;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Admins add, edit, move, remove and restore the items of a week's checklist on the week page's Checklist tab. Items are
 * bound through the week and the week through the program, so an item of another week is not found; neither is one of
 * another Gate than the week's checklist, nor an item removed from the checklist except to restore it.
 */
class AdminChecklistItemController extends Controller
{
    public function store(StoreChecklistItemRequest $request, Program $program, TrainingWeek $week, ChecklistService $checklists): RedirectResponse
    {
        $item = $checklists->addItem($request->checklist(), $request->details(), $request->user());

        return $this->toChecklistTab($program, $week)
            ->with('status', "Added \"{$item->title}\".");
    }

    public function update(UpdateChecklistItemRequest $request, Program $program, TrainingWeek $week, GateCriterion $checklistItem, ChecklistService $checklists): RedirectResponse
    {
        $checklists->updateItem($checklistItem, $request->details(), $request->user());

        return $this->toChecklistTab($program, $week)
            ->with('status', "Saved \"{$request->validated('title')}\".");
    }

    public function move(MoveCurriculumItemRequest $request, Program $program, TrainingWeek $week, GateCriterion $checklistItem, ChecklistService $checklists): RedirectResponse
    {
        abort_unless($checklistItem->isOnChecklist() && $checklistItem->training_gate_id === $week->checklist?->id, 404);
        $direction = $request->direction();

        if (! $checklists->moveItem($checklistItem, $direction, $request->user())) {
            return $this->toChecklistTab($program, $week);
        }

        return $this->toChecklistTab($program, $week)
            ->with('status', "Moved \"{$checklistItem->title}\" {$direction}.");
    }

    /**
     * Remove the item: it is deleted when no mentor has ticked it and retired otherwise, and the flash says which. When
     * another admin removed the checklist's other items in the meantime, the item stays and the error shows on the tab.
     */
    public function destroy(RemoveChecklistItemRequest $request, Program $program, TrainingWeek $week, GateCriterion $checklistItem, ChecklistService $checklists): RedirectResponse
    {
        try {
            $isDeleted = $checklists->removeItem($checklistItem, $request->user());
        } catch (ValidationException $exception) {
            throw $exception->errorBag('checklist')->redirectTo($this->checklistTabUrl($program, $week));
        }

        return $this->toChecklistTab($program, $week)
            ->with('status', ($isDeleted ? 'Deleted' : 'Removed')." \"{$checklistItem->title}\".");
    }

    public function restore(RestoreChecklistItemRequest $request, Program $program, TrainingWeek $week, GateCriterion $checklistItem, ChecklistService $checklists): RedirectResponse
    {
        $checklists->restoreItem($checklistItem, $request->user());

        return $this->toChecklistTab($program, $week)
            ->with('status', "Restored \"{$checklistItem->title}\".");
    }

    private function toChecklistTab(Program $program, TrainingWeek $week): RedirectResponse
    {
        return redirect()->to($this->checklistTabUrl($program, $week));
    }

    private function checklistTabUrl(Program $program, TrainingWeek $week): string
    {
        return route('admin.programs.weeks.show', [$program, $week]).'#checklist';
    }
}
