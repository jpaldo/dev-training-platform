<?php

namespace App\Http\Controllers;

use App\Models\InterviewScheduler;
use App\Models\User;
use App\Rules\ExternalBookingUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MentorBookingCalendarController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $booking = InterviewScheduler::query()->where('interview_type', InterviewScheduler::MOCK)->where('user_id', $user->id)->first();

        return view('coach.mentor-availability', compact('booking'));
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['booking_url' => ['required', 'string', 'max:2000', new ExternalBookingUrl]]);
        InterviewScheduler::query()->updateOrCreate(
            ['interview_type' => InterviewScheduler::MOCK, 'user_id' => $user->id],
            ['full_name' => $user->name, 'email' => $user->email, 'booking_url' => $data['booking_url'], 'mock_booking_url' => $data['booking_url'], 'is_active' => true, 'display_order' => 0],
        );

        return back()->with('status', 'Mock Interview booking link saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        InterviewScheduler::query()->where('interview_type', InterviewScheduler::MOCK)->where('user_id', $request->user()->id)->delete();

        return back()->with('status', 'Mock Interview booking link removed.');
    }
}
