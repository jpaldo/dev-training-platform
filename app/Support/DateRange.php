<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Formats a date range from its first to its last day, naming the month and year once when both days share them.
 */
class DateRange
{
    /**
     * "9 – 15 Nov 2026", "9 Nov – 6 Dec 2026" or "28 Dec 2026 – 3 Jan 2027".
     */
    public static function label(CarbonInterface $startsOn, CarbonInterface $endsOn): string
    {
        $startFormat = match (true) {
            $startsOn->year !== $endsOn->year => 'j M Y',
            $startsOn->month !== $endsOn->month => 'j M',
            default => 'j',
        };

        return $startsOn->format($startFormat).' – '.$endsOn->format('j M Y');
    }
}
