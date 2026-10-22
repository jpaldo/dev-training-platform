<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyLogRequest;
use App\Http\Requests\UpdateDailyLogRequest;
use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\User;
use App\Services\DailyLogPageService;
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
    public function index(Request $request, ExportLifecycleService $lifecycle, DailyLogPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->reconcileStale($user->id);

        return view('daily_logs.index', $page->indexData($user));
    }

    public function create(Request $request, DailyLogPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('daily_logs.create', $page->createData($user));
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

    public function edit(Request $request, DailyLog $dailyLog, DailyLogPageService $page): View
    {
        Gate::authorize('update', $dailyLog);
        /** @var User $user */
        $user = $request->user();

        return view('daily_logs.edit', $page->editData($dailyLog, $user));
    }

    public function update(UpdateDailyLogRequest $request, DailyLog $dailyLog, WeekProgressionService $progression, TrainingExportCoordinator $exports): RedirectResponse
    {
        /** @var User $user */ $user = $request->user();
        $data = $request->validated();
        if (! empty($data['activity_id']) && (int) $data['activity_id'] !== $dailyLog->activity_id) {
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
