<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['mentor_id', 'available_date', 'start_time', 'end_time', 'slot_duration_minutes', 'capacity', 'status'])]
class MentorInterviewAvailability extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(WeekFourMentorInterviewSchedule::class, 'availability_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return list<array{starts_at:CarbonImmutable,ends_at:CarbonImmutable}> */
    public function slots(?string $timezone = null): array
    {
        $timezone ??= (string) config('training.week_four_mentor_interview_timezone', config('app.timezone'));
        $date = CarbonImmutable::parse((string) $this->available_date, $timezone);
        $start = CarbonImmutable::parse($date->format('Y-m-d').' '.$this->start_time, $timezone);
        $end = CarbonImmutable::parse($date->format('Y-m-d').' '.$this->end_time, $timezone);
        $duration = (int) $this->slot_duration_minutes;
        $slots = [];

        while ($start->addMinutes($duration)->lte($end)) {
            $slots[] = ['starts_at' => $start, 'ends_at' => $start->addMinutes($duration)];
            $start = $start->addMinutes($duration);
        }

        return $slots;
    }
}
