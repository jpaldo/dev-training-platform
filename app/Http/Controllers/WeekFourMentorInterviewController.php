<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\MentorInterviewSchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WeekFourMentorInterviewController extends Controller
{
    public function store(Request $request, TrainingWeek $trainingWeek, Activity $activity, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        /** @var User $participant */
        $participant = $request->user();
        $activity->load('week');
        abort_unless($activity->training_week_id === $trainingWeek->id, 404);
        $data = $request->validate(['scheduled_at' => ['required', 'date_format:Y-m-d\\TH:i']], ['scheduled_at.required' => 'Please select your interview date and time.', 'scheduled_at.date_format' => 'Please select a valid interview date and time.']);
        $scheduling->schedule($participant, $activity, $data['scheduled_at']);

        return back()->with('status', 'Your Mentor mock interview has been scheduled.');
    }

    public function update(Request $request, TrainingWeek $trainingWeek, Activity $activity, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        /** @var User $participant */
        $participant = $request->user();
        $activity->load('week');
        abort_unless($activity->training_week_id === $trainingWeek->id, 404);
        $data = $request->validate(['scheduled_at' => ['required', 'date_format:Y-m-d\\TH:i']], ['scheduled_at.required' => 'Please select your interview date and time.', 'scheduled_at.date_format' => 'Please select a valid interview date and time.']);
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
