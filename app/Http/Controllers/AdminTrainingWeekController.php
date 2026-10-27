<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTrainingWeekRequest;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumWeekService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins edit a program week's title and description. Weeks are bound through the program, so a week of another
 * program is not found.
 */
class AdminTrainingWeekController extends Controller
{
    public function update(UpdateTrainingWeekRequest $request, Program $program, TrainingWeek $week, CurriculumWeekService $curriculum): RedirectResponse
    {
        $curriculum->updateWeek($week, $request->validated(), $request->user());

        return redirect()
            ->to(route('admin.programs.weeks.show', [$program, $week]).'#details')
            ->with('status', "Saved \"Week {$week->week_number}\".");
    }
}
