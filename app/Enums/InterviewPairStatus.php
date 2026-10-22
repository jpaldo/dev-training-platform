<?php

namespace App\Enums;

/**
 * Whether a Week 4 peer interview pairing is still in use (stored in `week_four_interview_pairs.status`,
 * default `active`). Unpairing makes a pair `Inactive`, or `Closed` when the partners already relied on it.
 */
enum InterviewPairStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Closed = 'closed';
}
