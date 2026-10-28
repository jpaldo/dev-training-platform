<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateProgramRequest;
use App\Models\Program;
use App\Services\CurriculumService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admins duplicate a curriculum's content (weeks, activities and their fields, checklists) into a new curriculum under
 * another name, for example to run another cohort of it.
 */
class AdminCurriculumDuplicateController extends Controller
{
    public function create(Program $program): View
    {
        Gate::authorize('create', Program::class);
        Gate::authorize('view', $program);

        return view('admin.curriculum.duplicate', [
            'sourceName' => $program->name,
            'sourceDescription' => $program->description,
            'storeUrl' => route('admin.programs.duplicate', $program),
            'cancelUrl' => route('admin.programs.show', $program),
        ]);
    }

    /**
     * Create the copy, dated when a start date was given, and open its Weeks tab.
     */
    public function store(DuplicateProgramRequest $request, Program $program, CurriculumService $curricula): RedirectResponse
    {
        $copy = $curricula->duplicate(
            source: $program,
            name: $request->string('name')->toString(),
            description: $request->validated('description'),
            startsOn: $request->date('starts_on'),
            isOffered: $request->boolean('offered'),
            admin: $request->user(),
        );

        return redirect()
            ->to(route('admin.programs.show', $copy).'#weeks')
            ->with('status', "Created {$copy->name} as a copy of {$program->name}.");
    }
}
