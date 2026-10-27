<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateChecklistItemRequest;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins edit the items of a week's checklist on the week page's Checklist tab. Items are bound through the week and
 * the week through the program, so an item of another week is not found; neither is an item removed from the
 * checklist or one of another Gate than the week's checklist.
 */
class AdminChecklistItemController extends Controller
{
    public function update(UpdateChecklistItemRequest $request, Program $program, TrainingWeek $week, GateCriterion $checklistItem, ChecklistService $checklists): RedirectResponse
    {
        $checklists->updateItem($checklistItem, $request->details(), $request->user());

        return redirect()
            ->to(route('admin.programs.weeks.show', [$program, $week]).'#checklist')
            ->with('status', "Saved \"{$request->validated('title')}\".");
    }
}
