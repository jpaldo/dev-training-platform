<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleMentorInterviewRequest;
use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\MentorInterviewSchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WeekFourMentorInterviewController extends Controller
{
    public function store(ScheduleMentorInterviewRequest $request, TrainingWeek $trainingWeek, Activity $activity, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        /** @var User $participant */
        $participant = $request->user();
        $activity->load('week');
        $data = $request->validated();
        $scheduling->schedule($participant, $activity, $data['scheduled_at']);

        return back()->with('status', 'Your Mentor mock interview has been scheduled.');
    }

    public function update(ScheduleMentorInterviewRequest $request, TrainingWeek $trainingWeek, Activity $activity, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        /** @var User $participant */
        $participant = $request->user();
        $activity->load('week');
        $data = $request->validated();
        $scheduling->reschedule($participant, $activity, $data['scheduled_at']);

        return back()->with('status', 'Your Mentor mock interview has been rescheduled.');
    }

    public function cancel(Request $request, WeekFourMentorInterviewSchedule $schedule, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $scheduling->cancel($actor, $schedule->load('participant'));

        return back()->with('status', 'The Mentor mock interview has been cancelled.');
    }
}
