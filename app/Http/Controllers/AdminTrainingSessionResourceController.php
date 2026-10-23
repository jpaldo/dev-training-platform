<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingSessionResourceRequest;
use App\Http\Requests\UpdateTrainingSessionResourceRequest;
use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Services\TrainingSessionResourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admins add, rename and remove a session's resources. Resources are bound through the session, so a resource of
 * another session is not found.
 */
class AdminTrainingSessionResourceController extends Controller
{
    public function store(StoreTrainingSessionResourceRequest $request, TrainingSession $trainingSession, TrainingSessionResourceService $resources): RedirectResponse
    {
        $resource = $resources->add($trainingSession, $request->safe()->only(['title', 'week_number', 'url']), $request->user());

        return $this->toResourcesTab($trainingSession)->with('status', "Added \"{$resource->title}\".");
    }

    public function update(UpdateTrainingSessionResourceRequest $request, TrainingSession $trainingSession, TrainingSessionResource $resource, TrainingSessionResourceService $resources): RedirectResponse
    {
        $resources->rename($resource, $request->validated('title'), $request->user());

        return $this->toResourcesTab($trainingSession)->with('status', "Renamed the resource to \"{$resource->title}\".");
    }

    public function destroy(Request $request, TrainingSession $trainingSession, TrainingSessionResource $resource, TrainingSessionResourceService $resources): RedirectResponse
    {
        Gate::authorize('manage', $trainingSession);

        $resources->remove($resource, $request->user());

        return $this->toResourcesTab($trainingSession)->with('status', "Removed \"{$resource->title}\".");
    }

    private function toResourcesTab(TrainingSession $trainingSession): RedirectResponse
    {
        return redirect()->to(route('admin.sessions.show', $trainingSession).'#resources');
    }
}
