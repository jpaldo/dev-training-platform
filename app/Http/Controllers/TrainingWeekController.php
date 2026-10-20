<?php

namespace App\Http\Controllers;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\WeekProgressionService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingWeekController extends Controller
{
    public function show(Request $request, TrainingWeek $trainingWeek, WeekProgressionService $progression): View
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingWeek);
        $trainingWeek->load(['program', 'activities' => fn (Relation $query): Relation => $query->where('is_active', true)->orderBy('position'), 'gates.criteria']);
        $gateEvaluation = $trainingWeek->gates->first()?->evaluations()->where('user_id', $user->id)->with('criterionEvaluations')->first();
        $videoRecording = $user->role === 'participant' ? WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $trainingWeek->id)->first() : null;

        return view('weeks.show', compact('trainingWeek', 'user', 'gateEvaluation', 'videoRecording'));
    }
}
