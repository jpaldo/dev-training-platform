<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWeekThreeWorkspaceRequest;
use App\Models\TrainingWeek;
use App\Services\WeekThreeWorkspaceService;
use Illuminate\Http\RedirectResponse;

class WeekThreeWorkspaceController extends Controller
{
    public function update(UpdateWeekThreeWorkspaceRequest $request, TrainingWeek $trainingWeek, WeekThreeWorkspaceService $workspaceService): RedirectResponse
    {
        $payload = $request->validated();
        $workspaceService->save($request->user(), $trainingWeek, $payload);

        return back()->with('status', 'Week 3 workspace saved.');
    }
}
