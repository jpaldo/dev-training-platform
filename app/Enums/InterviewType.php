<?php

namespace App\Enums;

/**
 * Which interviews an interviewer listing offers (stored in `interview_schedulers.interview_type`).
 * A `Both` interviewer is listed for Mock and Final Re-assessment interviews alike.
 */
enum InterviewType: string
{
    case Mock = 'mock';
    case FinalReassessment = 'final_reassessment';
    case Both = 'both';

    /**
     * The interview type option text of the interviewer forms (admin and public registration).
     */
    public function label(): string
    {
        return match ($this) {
            self::Mock => 'Mock Interview',
            self::FinalReassessment => 'Final Re-assessment Interview',
            self::Both => 'Both',
        };
    }

    /**
     * Whether an interviewer of this type offers the given interview (a `Both` interviewer offers either).
     */
    public function offers(self $interview): bool
    {
        return $this === $interview || $this === self::Both;
    }

    /**
     * The interviewer types that offer the given interview: its own type and `Both`.
     *
     * @return list<self>
     */
    public static function offering(self $interview): array
    {
        return [$interview, self::Both];
    }
}
