<?php

namespace App\Enums;

/**
 * Where a QA Validation Lab record stands (stored in `validation_submissions.status`). The six-check form creates
 * records as `Submitted` (also the column default), and a `Draft` record is submitted with "Submit Lab Record".
 * No code writes `Completed`, but a completed record counts as recorded wherever a submitted one does.
 */
enum ValidationSubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Completed = 'completed';

    /**
     * The statuses of a recorded Lab record: one that unlocks the Week 2 Validation Checklist and can be linked to it.
     *
     * @return list<self>
     */
    public static function recorded(): array
    {
        return [self::Submitted, self::Completed];
    }
}
