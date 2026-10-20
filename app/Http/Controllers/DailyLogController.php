<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyLogRequest;
use App\Http\Requests\UpdateDailyLogRequest;
use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\GoogleDriveExport;
use App\Models\Program;
use App\Models\User;
use App\Services\ExportLifecycleService;
use App\Services\TrainingExportCoordinator;
use App\Services\TrainingSyncCoordinator;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DailyLogController extends Controller
{
    public function index(Request $request, ExportLifecycleService $lifecycle): View
    {
        /** @var User $user */ $user = $request->user();
        $lifecycle->reconcileStale($user->id);

        $program = Program::query()->where('is_active', true)->with('weeks')->first();
        $weeklyProgress = $program ? $program->weeks->where('is_active', true)->sortBy('week_number')->map(fn ($week): array => [
            'week' => $week,
            'count' => $user->dailyLogs()->whereHas('activity', fn ($query) => $query->where('training_week_id', $week->id))->count(),
        ])->values() : collect();

        $exports = GoogleDriveExport::query()->where('user_id', $user->id)->where('export_type', 'daily_logs')->get()->keyBy('scope_key');

        return view('daily_logs.index', ['logs' => $user->dailyLogs()->with('activity.week')->latest('logged_on')->latest('id')->paginate(25), 'weeklyProgress' => $weeklyProgress, 'exports' => $exports]);
    }

    public function create(Request $request, WeekProgressionService $progression): View
    {
        /** @var User $user */
        $user = $request->user();
        $activities = Activity::query()->with('week')->where('is_active', true)->orderBy('training_week_id')->orderBy('position')->get()->filter(fn (Activity $activity): bool => $progression->canAccessWeek($user, $activity->week));

        return view('daily_logs.create', compact('activities'));
    }

    public function store(StoreDailyLogRequest $request, WeekProgressionService $progression, TrainingExportCoordinator $exports): RedirectResponse
    {
        /** @var User $user */ $user = $request->user();
        Gate::authorize('create', DailyLog::class);
        $data = $request->validated();
        if (! empty($data['activity_id'])) {
            $activity = Activity::with('week')->findOrFail($data['activity_id']);
            $progression->ensureCanAccessActivity($user, $activity->week, $activity);
        }
        $log = $user->dailyLogs()->create($data)->load('activity.week');
        if ($log->activity?->week?->week_number) {
            $exports->queueDailyLogs($user, $log->activity->week->week_number);
        }
        app(TrainingSyncCoordinator::class)->request($user, 'daily_log_created');

        return redirect()->route('daily-logs.index')->with('status', 'Daily log saved.');
    }

    public function edit(DailyLog $dailyLog): View
    {
        Gate::authorize('update', $dailyLog);

        return view('daily_logs.edit', compact('dailyLog'));
    }

    public function update(UpdateDailyLogRequest $request, DailyLog $dailyLog, WeekProgressionService $progression, TrainingExportCoordinator $exports): RedirectResponse
    {
        /** @var User $user */ $user = $request->user();
        $data = $request->validated();
        if (! empty($data['activity_id'])) {
            $activity = Activity::with('week')->findOrFail($data['activity_id']);
            $progression->ensureCanAccessActivity($user, $activity->week, $activity);
        }
        $dailyLog->update($data);
        $dailyLog->load('activity.week');
        if ($dailyLog->activity?->week?->week_number) {
            $exports->queueDailyLogs($user, $dailyLog->activity->week->week_number);
        }
        app(TrainingSyncCoordinator::class)->request($user, 'daily_log_updated');

        return redirect()->route('daily-logs.index')->with('status', 'Daily log updated.');
    }
}
