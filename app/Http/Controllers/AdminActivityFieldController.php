<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateActivityFieldRequest;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\ActivityFieldService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins edit an activity's submission fields on the activity page's Submission fields tab. Fields are bound through
 * the activity, the activity through the week and the week through the program, so a field of another activity is not
 * found.
 */
class AdminActivityFieldController extends Controller
{
    public function update(UpdateActivityFieldRequest $request, Program $program, TrainingWeek $week, Activity $activity, ActivityRequirement $requirement, ActivityFieldService $fields): RedirectResponse
    {
        $fields->update($requirement, $request->settings(), $request->user());

        return redirect()
            ->to(route('admin.programs.activities.edit', [$program, $week, $activity]).'#fields')
            ->with('status', "Saved \"{$request->validated('label')}\".");
    }
}
