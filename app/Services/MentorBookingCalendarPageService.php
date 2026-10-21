<?php

namespace App\Services;

use App\Models\InterviewScheduler;
use App\Models\User;

class MentorBookingCalendarPageService
{
    public function __construct(private readonly InterviewBookingLinkService $bookingLinks) {}

    /**
     * Everything the mentor booking calendar page renders: whether the mentor has saved a Mock Interview booking
     * link and, if so, the link participants book through (the mock link, or the general booking link when no
     * mock link is set). The single main action is Save Booking Link.
     *
     * @return array{hasBooking: bool, bookingUrl: ?string}
     */
    public function indexData(User $mentor): array
    {
        $booking = InterviewScheduler::query()
            ->where('interview_type', InterviewScheduler::MOCK)
            ->where('user_id', $mentor->id)
            ->first();

        return [
            'hasBooking' => $booking !== null,
            'bookingUrl' => $booking ? $this->bookingLinks->bookingUrl($booking, InterviewScheduler::MOCK) : null,
        ];
    }
}
