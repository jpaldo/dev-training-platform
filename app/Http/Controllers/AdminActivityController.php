<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeactivateActivityRequest;
use App\Http\Requests\MoveCurriculumItemRequest;
use App\Http\Requests\StoreActivityRequest;
use App\Http\Requests\UpdateActivityRequest;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumPageService;
use App\Services\CurriculumWeekService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admins add an activity to a week, open and edit a week's activity, and move, deactivate and reactivate it on the
 * Curriculum pages. Activities are bound through the week and weeks through the program, so an activity of another
 * week or a week of another program is not found.
 */
class AdminActivityController extends Controller
{
    public function store(StoreActivityRequest $request, Program $program, TrainingWeek $week, CurriculumWeekService $curriculum): RedirectResponse
    {
        $activity = $curriculum->addActivity($week, $request->validated(), $request->user());

        return redirect()
            ->route('admin.programs.activities.edit', [$program, $week, $activity])
            ->with('status', "Added \"{$activity->title}\".");
    }

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

    public function move(MoveCurriculumItemRequest $request, Program $program, TrainingWeek $week, Activity $activity, CurriculumWeekService $curriculum): RedirectResponse
    {
        $direction = $request->direction();

        if (! $curriculum->moveActivity($activity, $direction, $request->user())) {
            return $this->toActivitiesTab($program, $week);
        }

        return $this->toActivitiesTab($program, $week)
            ->with('status', "Moved \"{$activity->title}\" {$direction}.");
    }

    public function deactivate(DeactivateActivityRequest $request, Program $program, TrainingWeek $week, Activity $activity, CurriculumWeekService $curriculum): RedirectResponse
    {
        $curriculum->deactivateActivity($activity, $request->user());

        return $this->toActivitiesTab($program, $week)
            ->with('status', "Deactivated \"{$activity->title}\".");
    }

    public function reactivate(Request $request, Program $program, TrainingWeek $week, Activity $activity, CurriculumWeekService $curriculum): RedirectResponse
    {
        Gate::authorize('update', $program);

        $curriculum->reactivateActivity($activity, $request->user());

        return $this->toActivitiesTab($program, $week)
            ->with('status', "Reactivated \"{$activity->title}\".");
    }

    private function toActivitiesTab(Program $program, TrainingWeek $week): RedirectResponse
    {
        return redirect()->to(route('admin.programs.weeks.show', [$program, $week]).'#activities');
    }
}
