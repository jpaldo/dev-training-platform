<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterCoachParticipantsRequest;
use App\Http\Requests\FilterParticipantDailyLogsRequest;
use App\Models\User;
use App\Services\CoachParticipantListPageService;
use App\Services\CoachParticipantPageService;
use App\Services\CoachReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CoachParticipantController extends Controller
{
    public function index(FilterCoachParticipantsRequest $request, CoachParticipantListPageService $page): View
    {
        return view('coach.participants', $page->indexData($request->user(), $request->validated()));
    }

    public function show(Request $request, User $participant, CoachParticipantPageService $page): View
    {
        abort_unless($participant->hasAnyRole(['participant']), 404);
        Gate::authorize('view', $participant);

        return view('coach.participant', $page->showData($request->user(), $participant));
    }

    public function dailyLogs(FilterParticipantDailyLogsRequest $request, User $participant, CoachReportService $reports): View
    {
        $validated = $request->validated();
        $weeks = $reports->activeWeeks();
        $selectedWeek = $weeks->firstWhere('week_number', (int) ($validated['week'] ?? 0));
        $logs = $reports->participantDailyLogs($participant, $selectedWeek, $validated['date'] ?? null)->paginate(25)->withQueryString();
        $participant->loadCount('dailyLogs');

        return view('coach.participant-daily-logs', compact('participant', 'logs', 'weeks', 'selectedWeek'));
    }
}
