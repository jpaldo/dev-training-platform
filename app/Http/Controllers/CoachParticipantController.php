<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterCoachParticipantsRequest;
use App\Http\Requests\FilterParticipantDailyLogsRequest;
use App\Models\User;
use App\Services\CoachParticipantPageService;
use App\Services\CoachReportService;
use App\Services\WeekFourInterviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CoachParticipantController extends Controller
{
    public function index(FilterCoachParticipantsRequest $request, CoachReportService $reports, WeekFourInterviewService $interviews): View
    {
        $items = $reports->participants($request->validated())->paginate(25)->withQueryString();
        $pairs = $interviews->activePairs();
        $pairParticipants = $interviews->pairingCandidates();

        return view('coach.participants', compact('items', 'pairs', 'pairParticipants'));
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
