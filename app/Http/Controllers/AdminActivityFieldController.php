<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveCurriculumItemRequest;
use App\Http\Requests\StoreActivityFieldRequest;
use App\Http\Requests\UpdateActivityFieldRequest;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\ActivityFieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admins add, edit, move, remove and restore an activity's submission fields on the activity page's Submission fields
 * tab. Fields are bound through the activity, the activity through the week and the week through the program, so a
 * field of another activity is not found; neither is a requirement that is not a submission field.
 */
class AdminActivityFieldController extends Controller
{
    public function store(StoreActivityFieldRequest $request, Program $program, TrainingWeek $week, Activity $activity, ActivityFieldService $fields): RedirectResponse
    {
        $settings = $request->settings();
        $fields->add($activity, $settings, $request->user());

        return $this->toFieldsTab($program, $week, $activity)
            ->with('status', "Added \"{$settings['label']}\".");
    }

    public function update(UpdateActivityFieldRequest $request, Program $program, TrainingWeek $week, Activity $activity, ActivityRequirement $requirement, ActivityFieldService $fields): RedirectResponse
    {
        $fields->update($requirement, $request->settings(), $request->user());

        return $this->toFieldsTab($program, $week, $activity)
            ->with('status', "Saved \"{$request->validated('label')}\".");
    }

    public function move(MoveCurriculumItemRequest $request, Program $program, TrainingWeek $week, Activity $activity, ActivityRequirement $requirement, ActivityFieldService $fields): RedirectResponse
    {
        abort_unless($requirement->isSubmissionField() && ! $requirement->isRemovedField(), 404);
        $direction = $request->direction();

        if (! $fields->move($requirement, $direction, $request->user())) {
            return $this->toFieldsTab($program, $week, $activity);
        }

        return $this->toFieldsTab($program, $week, $activity)
            ->with('status', "Moved \"{$requirement->fieldSettings()['label']}\" {$direction}.");
    }

    public function destroy(Request $request, Program $program, TrainingWeek $week, Activity $activity, ActivityRequirement $requirement, ActivityFieldService $fields): RedirectResponse
    {
        abort_unless($requirement->isSubmissionField(), 404);
        Gate::authorize('update', $program);

        $fields->retire($requirement, $request->user());

        return $this->toFieldsTab($program, $week, $activity)
            ->with('status', "Removed \"{$requirement->fieldSettings()['label']}\".");
    }

    public function restore(Request $request, Program $program, TrainingWeek $week, Activity $activity, ActivityRequirement $requirement, ActivityFieldService $fields): RedirectResponse
    {
        abort_unless($requirement->isSubmissionField(), 404);
        Gate::authorize('update', $program);

        $fields->restore($requirement, $request->user());

        return $this->toFieldsTab($program, $week, $activity)
            ->with('status', "Restored \"{$requirement->fieldSettings()['label']}\".");
    }

    private function toFieldsTab(Program $program, TrainingWeek $week, Activity $activity): RedirectResponse
    {
        return redirect()->to(route('admin.programs.activities.edit', [$program, $week, $activity]).'#fields');
    }
}
