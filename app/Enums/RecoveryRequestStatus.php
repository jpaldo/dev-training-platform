<?php

namespace App\Enums;

/**
 * Where a participant's request to reconsider a failed attempt stands (stored in `activity_recovery_requests.status`).
 * Requests start `Pending` (the column default); a coach decides them as `Approved` (a retry attempt is opened) or
 * `Declined` (the attempt stays failed).
 */
enum RecoveryRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';

    /**
     * The statuses a coach can decide a pending request with.
     *
     * @return list<self>
     */
    public static function decisions(): array
    {
        return [self::Approved, self::Declined];
    }
}
