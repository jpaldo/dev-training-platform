<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\TrainingWeekPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingWeekController extends Controller
{
    /**
     * The week page within a session. The route has already checked that the user can view the session and that the
     * week is one of its weeks.
     */
    public function show(Request $request, TrainingSession $trainingSession, TrainingWeek $trainingWeek, WeekProgressionService $progression, TrainingWeekPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingSession, $trainingWeek);

        return view('weeks.show', $page->showData($trainingSession, $trainingWeek, $user));
    }
}
