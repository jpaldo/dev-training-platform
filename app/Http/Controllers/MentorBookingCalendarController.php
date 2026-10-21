<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMentorBookingCalendarRequest;
use App\Models\InterviewScheduler;
use App\Models\User;
use App\Services\MentorBookingCalendarPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MentorBookingCalendarController extends Controller
{
    public function index(Request $request, MentorBookingCalendarPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('coach.mentor-availability', $page->indexData($user));
    }

    public function store(StoreMentorBookingCalendarRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
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
