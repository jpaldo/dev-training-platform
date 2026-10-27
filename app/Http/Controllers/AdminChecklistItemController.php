<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveCurriculumItemRequest;
use App\Http\Requests\StoreChecklistItemRequest;
use App\Http\Requests\UpdateChecklistItemRequest;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins add, edit and move the items of a week's checklist on the week page's Checklist tab. Items are bound through
 * the week and the week through the program, so an item of another week is not found; neither is an item removed from
 * the checklist or one of another Gate than the week's checklist.
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

    private function toChecklistTab(Program $program, TrainingWeek $week): RedirectResponse
    {
        return redirect()->to(route('admin.programs.weeks.show', [$program, $week]).'#checklist');
    }
}
