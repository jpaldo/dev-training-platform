<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\AdminProgramDashboardService;
use App\Services\CurriculumPageService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminCurriculumController extends Controller
{
    public function index(CurriculumPageService $page): View
    {
        Gate::authorize('viewAny', Program::class);

        return view('admin.curriculum.index', $page->indexData());
    }

    /**
     * The tabbed curriculum page: its header, Weeks and Schedule tabs (CurriculumPageService) beside the Overview,
     * Participants, Mentors, Activities and Resources tabs (AdminProgramDashboardService). The `role:admin` route
     * middleware keeps it admin-only, since `view` also lets the curriculum's participants and mentors in.
     */
    public function show(Program $program, CurriculumPageService $page, AdminProgramDashboardService $dashboard): View
    {
        Gate::authorize('view', $program);

        $program->loadScheduleBounds();

        return view('admin.curriculum.program', [
            ...$page->programData($program),
            ...$dashboard->showData($program),
        ]);
    }

    public function week(Program $program, TrainingWeek $week, CurriculumPageService $page): View
    {
        Gate::authorize('view', $program);

        return view('admin.curriculum.week', $page->weekData($program, $week));
    }
}
