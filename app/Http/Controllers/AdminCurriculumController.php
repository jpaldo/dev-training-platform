<?php

namespace App\Http\Controllers;

use App\Models\Program;
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

    public function show(Program $program, CurriculumPageService $page): View
    {
        Gate::authorize('view', $program);

        return view('admin.curriculum.program', $page->programData($program));
    }
}
