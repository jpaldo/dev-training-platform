<?php

namespace App\Services;

use App\Enums\InterviewType;
use App\Models\User;

class MentorBookingCalendarPageService
{
    public function __construct(private readonly InterviewBookingLinkService $bookingLinks) {}

    /**
     * Everything the mentor booking calendar page renders: whether the mentor has an active Mock Interview booking
     * listing (their own `Mock` listing or a `Both` listing an Admin linked to their account) and, if so, the link
     * participants book through (the mock link, or the general booking link when no mock link is set). The single main action is Save Booking Link.
     *
     * @return array{hasBooking: bool, bookingUrl: ?string}
     */
    public function indexData(User $mentor): array
    {
        $booking = $this->bookingLinks->forMentor($mentor);

        return [
            'hasBooking' => $booking !== null,
            'bookingUrl' => $booking ? $this->bookingLinks->bookingUrl($booking, InterviewType::Mock) : null,
        ];
    }
}
