<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\TrainingWeekPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingWeekController extends Controller
{
    /**
     * The week page within a curriculum. The route has already checked that the user can view the curriculum and that
     * the week is one of its weeks.
     */
    public function show(Request $request, Program $program, TrainingWeek $trainingWeek, WeekProgressionService $progression, TrainingWeekPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $program, $trainingWeek);

        return view('weeks.show', $page->showData($program, $trainingWeek, $user));
    }
}
