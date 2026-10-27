<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateActivityRequest;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumPageService;
use App\Services\CurriculumWeekService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admins open and edit a week's activity on the Curriculum pages. Activities are bound through the week and weeks
 * through the program, so an activity of another week or a week of another program is not found.
 */
class AdminActivityController extends Controller
{
    public function edit(Program $program, TrainingWeek $week, Activity $activity, CurriculumPageService $page): View
    {
        Gate::authorize('view', $program);

        return view('admin.curriculum.activity', $page->activityData($program, $week, $activity));
    }

    public function update(UpdateActivityRequest $request, Program $program, TrainingWeek $week, Activity $activity, CurriculumWeekService $curriculum): RedirectResponse
    {
        $curriculum->updateActivity($activity, $request->validated(), $request->user());

        return redirect()
            ->to(route('admin.programs.activities.edit', [$program, $week, $activity]).'#details')
            ->with('status', "Saved \"{$activity->title}\".");
    }
}
