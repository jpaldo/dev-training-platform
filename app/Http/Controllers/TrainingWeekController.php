<?php

namespace App\Http\Controllers;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\TrainingWeekPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingWeekController extends Controller
{
    public function show(Request $request, TrainingWeek $trainingWeek, WeekProgressionService $progression, TrainingWeekPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingWeek);

        return view('weeks.show', $page->showData($trainingWeek, $user));
    }
}
