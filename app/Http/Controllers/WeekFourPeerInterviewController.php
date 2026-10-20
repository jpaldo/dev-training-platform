<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignWeekFourPairRequest;
use App\Http\Requests\StorePartnerFeedbackRequest;
use App\Http\Requests\WaiveWeekFourPeerInterviewRequest;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Services\WeekFourInterviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WeekFourPeerInterviewController extends Controller
{
    public function questionnaire(WeekFourInterviewService $interviews): View
    {
        return view('coach.week-four-questionnaire', ['questions' => $interviews->questionnaire()]);
    }

    public function assignPair(AssignWeekFourPairRequest $request, WeekFourInterviewService $interviews): RedirectResponse
    {
        $validated = $request->validated();
        $first = User::query()->findOrFail($validated['participant_one_id']);
        $second = User::query()->findOrFail($validated['participant_two_id']);
        $interviews->assign($request->user(), $first, $second);

        return back()->with('status', 'Week 4 interview partners assigned reciprocally.');
    }

    public function unpair(Request $request, WeekFourInterviewPair $pair, WeekFourInterviewService $interviews): RedirectResponse
    {
        $interviews->unpair($request->user(), $pair->load(['participantOne', 'participantTwo']));

        return back()->with('status', 'The Week 4 interview pairing was safely deactivated.');
    }

    public function waive(WaiveWeekFourPeerInterviewRequest $request, User $participant, WeekFourInterviewService $interviews): RedirectResponse
    {
        $interviews->waive($request->user(), $participant, $interviews->peerInterviewActivityOrFail(), $request->validated('reason'));

        return back()->with('status', 'Peer mock interview requirement waived for this participant.');
    }

    public function revokeWaiver(Request $request, User $participant, WeekFourInterviewService $interviews): RedirectResponse
    {
        abort_unless($participant->role === 'participant', 404);
        $interviews->revokeWaiver($request->user(), $participant, $interviews->peerInterviewActivityOrFail());

        return back()->with('status', 'Peer mock interview waiver revoked.');
    }

    public function storePartnerFeedback(StorePartnerFeedbackRequest $request, WeekFourInterviewService $interviews): RedirectResponse
    {
        $validated = $request->validated();
        $interviews->saveFeedback($request->user(), (int) $validated['question_number'], $validated['content'], isset($validated['pair_id']) ? (int) $validated['pair_id'] : null);

        return back()->with('status', 'Your feedback was saved for your partner.');
    }
}
