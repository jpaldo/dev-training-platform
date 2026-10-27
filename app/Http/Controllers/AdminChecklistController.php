<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateChecklistRequest;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins edit the title and description of a week's checklist on the week page's Checklist tab. Weeks are bound
 * through the program, so a week of another program is not found; neither is a week without a checklist.
 */
class AdminChecklistController extends Controller
{
    public function update(UpdateChecklistRequest $request, Program $program, TrainingWeek $week, ChecklistService $checklists): RedirectResponse
    {
        $checklists->updateChecklist($request->checklist(), $request->validated(), $request->user());

        return redirect()
            ->to(route('admin.programs.weeks.show', [$program, $week]).'#checklist')
            ->with('status', "Saved \"{$request->validated('title')}\".");
    }
}
