<?php

namespace App\Http\Controllers;

use App\Models\TrainingWeek;
use App\Services\WeekThreeWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WeekThreeWorkspaceController extends Controller
{
    public function update(Request $request, TrainingWeek $trainingWeek, WeekThreeWorkspaceService $workspaceService): RedirectResponse
    {
        abort_unless($request->user()?->role === 'participant', 403);
        $payload = $request->validate([
            'regression_areas' => ['nullable', 'string', 'max:10000'],
            'regression_issues' => ['nullable', 'string', 'max:10000'],
            'coverage_gaps' => ['nullable', 'string', 'max:10000'],
            'final_conclusion' => ['nullable', 'string', 'max:10000'],
            'findings' => ['nullable', 'array', 'max:5'],
            'findings.*' => ['array'],
            'findings.*.title' => ['nullable', 'string', 'max:255'],
            'findings.*.description' => ['nullable', 'string', 'max:10000'],
            'findings.*.steps_to_reproduce' => ['nullable', 'string', 'max:10000'],
            'findings.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.actual_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.observed_evidence' => ['nullable', 'string', 'max:10000'],
            'findings.*.ai_tool' => ['nullable', 'string', 'max:255'],
            'findings.*.ai_proposed_root_cause' => ['nullable', 'string', 'max:10000'],
            'findings.*.human_validation' => ['nullable', 'in:supported,partially_supported,unsupported'],
            'findings.*.human_validation_explanation' => ['nullable', 'string', 'max:10000'],
            'findings.*.severity' => ['nullable', 'string', 'max:100'],
            'findings.*.blast_radius' => ['nullable', 'string', 'max:10000'],
            'findings.*.affected_areas' => ['nullable', 'string', 'max:10000'],
            'findings.*.next_step' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests' => ['nullable', 'array'],
            'findings.*.tests.*' => ['array'],
            'findings.*.tests.*.name' => ['nullable', 'string', 'max:255'],
            'findings.*.tests.*.preconditions' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.steps' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.actual_result' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.execution_result' => ['nullable', 'string', 'max:255'],
            'findings.*.tests.*.framework' => ['nullable', 'string', 'max:255'],
            'findings.*.tests.*.script_location' => ['nullable', 'string', 'max:1000'],
            'findings.*.tests.*.artifact_evidence' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.execution_evidence' => ['nullable', 'string', 'max:10000'],
            'findings.*.tests.*.fix_validation' => ['nullable', 'array'],
            'findings.*.tests.*.fix_validation.*' => ['nullable', 'string', 'max:10000'],
        ]);
        $workspaceService->save($request->user(), $trainingWeek, $payload);

        return back()->with('status', 'Week 3 workspace saved.');
    }
}
