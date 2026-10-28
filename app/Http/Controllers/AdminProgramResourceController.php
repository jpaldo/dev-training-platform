<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProgramResourceRequest;
use App\Http\Requests\UpdateProgramResourceRequest;
use App\Models\Program;
use App\Models\ProgramResource;
use App\Services\ProgramResourceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admins add, rename and remove a curriculum's resources. Resources are bound through the curriculum, so a resource of
 * another curriculum is not found.
 */
class AdminProgramResourceController extends Controller
{
    public function store(StoreProgramResourceRequest $request, Program $program, ProgramResourceService $resources): RedirectResponse
    {
        $resource = $resources->add($program, $request->safe()->only(['title', 'week_number', 'url']), $request->user());

        return $this->toResourcesTab($program)->with('status', "Added \"{$resource->title}\".");
    }

    public function update(UpdateProgramResourceRequest $request, Program $program, ProgramResource $resource, ProgramResourceService $resources): RedirectResponse
    {
        $resources->rename($resource, $request->validated('title'), $request->user());

        return $this->toResourcesTab($program)->with('status', "Renamed the resource to \"{$resource->title}\".");
    }

    public function destroy(Request $request, Program $program, ProgramResource $resource, ProgramResourceService $resources): RedirectResponse
    {
        Gate::authorize('manage', $program);

        $resources->remove($resource, $request->user());

        return $this->toResourcesTab($program)->with('status', "Removed \"{$resource->title}\".");
    }

    private function toResourcesTab(Program $program): RedirectResponse
    {
        return redirect()->to(route('admin.programs.show', $program).'#resources');
    }
}
