<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumPageService;
use App\Services\ProgramOverviewTabService;
use App\Services\ProgramPeopleTabService;
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
     * The tabbed curriculum page: its header, Weeks and Schedule tabs (CurriculumPageService), its Participants and
     * Mentors tabs (ProgramPeopleTabService) and its tab list with the Overview, Activities and Resources tabs
     * (ProgramOverviewTabService), the people read once for both. The `role:admin` route middleware keeps it
     * admin-only, since `view` also lets the curriculum's participants and mentors in.
     */
    public function show(Program $program, CurriculumPageService $page, ProgramPeopleTabService $peopleTabs, ProgramOverviewTabService $overviewTabs): View
    {
        Gate::authorize('view', $program);

        $program->loadScheduleBounds();
        $roster = $peopleTabs->roster($program);

        return view('admin.curriculum.program', [
            ...$page->programData($program),
            ...$peopleTabs->tabData($program, $roster),
            ...$overviewTabs->tabData($program, $roster),
        ]);
    }

    public function week(Program $program, TrainingWeek $week, CurriculumPageService $page): View
    {
        Gate::authorize('view', $program);

        return view('admin.curriculum.week', $page->weekData($program, $week));
    }
}
