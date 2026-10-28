<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProgramRequest;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Services\CurriculumPageService;
use App\Services\CurriculumService;
use App\Services\ProgramOverviewTabService;
use App\Services\ProgramPeopleTabService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminCurriculumController extends Controller
{
    public function index(CurriculumPageService $page): View
    {
        Gate::authorize('viewAny', Program::class);

        return view('admin.curriculum.index', $page->indexData());
    }

    public function create(): View
    {
        Gate::authorize('create', Program::class);

        return view('admin.curriculum.create', [
            'storeUrl' => route('admin.programs.store'),
            'indexUrl' => route('admin.programs.index'),
            'maxWeeks' => CurriculumService::MAX_WEEKS,
        ]);
    }

    /**
     * Create the curriculum with its weeks and checklists, dated when a start date was given, and open its Weeks tab.
     */
    public function store(StoreProgramRequest $request, CurriculumService $curricula): RedirectResponse
    {
        $weeksCount = $request->integer('weeks_count');

        $program = $curricula->create(
            name: $request->string('name')->toString(),
            description: $request->validated('description'),
            weeksCount: $weeksCount,
            startsOn: $request->date('starts_on'),
            isOffered: $request->boolean('offered'),
            admin: $request->user(),
        );

        return redirect()
            ->to(route('admin.programs.show', $program).'#weeks')
            ->with('status', "Created {$program->name} with {$weeksCount} ".Str::plural('week', $weeksCount).'.');
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
