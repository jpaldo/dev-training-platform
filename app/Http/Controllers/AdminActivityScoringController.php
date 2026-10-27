<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateActivityScoringRequest;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumWeekService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins edit an activity's scoring dimensions on the activity page's Scoring tab until the activity has its first
 * score. The activity is bound through the week and the week through the program, so an activity of another week or a
 * week of another program is not found.
 */
class AdminActivityScoringController extends Controller
{
    public function update(UpdateActivityScoringRequest $request, Program $program, TrainingWeek $week, Activity $activity, CurriculumWeekService $curriculum): RedirectResponse
    {
        $curriculum->updateScoringDimensions($activity, $request->dimensions(), $request->user());

        return redirect()
            ->to(route('admin.programs.activities.edit', [$program, $week, $activity]).'#scoring')
            ->with('status', "Saved the scoring dimensions of \"{$activity->title}\".");
    }
}
