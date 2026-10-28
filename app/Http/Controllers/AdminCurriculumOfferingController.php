<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProgramOfferingRequest;
use App\Models\Program;
use App\Services\CurriculumService;
use Illuminate\Http\RedirectResponse;

/**
 * Admins offer a curriculum to participants, who can then join it, or withdraw it, which only stops new joins.
 */
class AdminCurriculumOfferingController extends Controller
{
    public function update(UpdateProgramOfferingRequest $request, Program $program, CurriculumService $curricula): RedirectResponse
    {
        $isOffered = $request->boolean('offered');

        $curricula->setOffered($program, $isOffered, $request->user());

        return redirect()
            ->to(route('admin.programs.show', $program).'#overview')
            ->with('status', $isOffered
                ? "{$program->name} is offered to participants."
                : "{$program->name} is no longer offered to participants.");
    }
}
