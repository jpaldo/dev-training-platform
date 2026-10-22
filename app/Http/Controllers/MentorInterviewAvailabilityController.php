<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreMentorInterviewAvailabilityRequest;
use App\Http\Requests\UpdateMentorInterviewAvailabilityRequest;
use App\Models\MentorInterviewAvailability;
use App\Models\User;
use App\Services\MentorInterviewAvailabilityService;
use App\Services\MentorInterviewSchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MentorInterviewAvailabilityController extends Controller
{
    public function index(Request $request, MentorInterviewAvailabilityService $availability): View
    {
        $actor = $request->user();
        $mentors = $actor->role === UserRole::Admin ? User::query()->where('role', UserRole::Coach)->where('is_active', true)->orderBy('name')->get() : collect([$actor]);
        $mentorId = (int) $request->integer('mentor_id', $mentors->first()?->id);
        $mentor = $mentors->firstWhere('id', $mentorId) ?: $mentors->first();
        $items = $mentor ? $availability->listForMentor($mentor) : collect();
        $slots = $mentor ? $availability->futureSlotsForMentor($mentor) : collect();

        return view('coach.mentor-availability', compact('items', 'mentor', 'mentors', 'slots'));
    }

    public function store(StoreMentorInterviewAvailabilityRequest $request, MentorInterviewAvailabilityService $availability): RedirectResponse
    {
        $data = $request->validated();
        $mentor = $request->user()->role === UserRole::Admin ? User::query()->where('role', UserRole::Coach)->findOrFail($data['mentor_id']) : $request->user();
        $availability->create($mentor, $data);

        return back()->with('status', 'Mentor interview availability added.');
    }

    public function update(UpdateMentorInterviewAvailabilityRequest $request, MentorInterviewAvailability $availability, MentorInterviewAvailabilityService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->update($request->user(), $availability, $data);

        return back()->with('status', 'Mentor interview availability updated.');
    }

    public function close(Request $request, MentorInterviewAvailability $availability, MentorInterviewAvailabilityService $service): RedirectResponse
    {
        $service->close($request->user(), $availability);

        return back()->with('status', 'Mentor interview availability closed.');
    }

    public function participantIndex(Request $request, MentorInterviewAvailabilityService $availability, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        return redirect()->route('dashboard');
    }

    public function book(Request $request, MentorInterviewAvailabilityService $availability, MentorInterviewSchedulingService $scheduling): RedirectResponse
    {
        return redirect()->route('dashboard')->withErrors(['mentor_interview' => 'Mentor interviews are scheduled through the assigned Mentor’s external booking page.']);
    }
}
