<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekFourMentorInterviewSchedule extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['participant_id', 'mentor_id', 'activity_id', 'availability_id', 'starts_at', 'ends_at', 'timezone', 'duration_minutes', 'status', 'google_event_id', 'google_meet_url', 'google_conference_id', 'failure_reason', 'scheduled_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'scheduled_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(MentorInterviewAvailability::class, 'availability_id');
    }

    public function startsAtInTimezone(?string $timezone = null): CarbonImmutable
    {
        return $this->rawTimestampInTimezone('starts_at', $timezone);
    }

    public function endsAtInTimezone(?string $timezone = null): CarbonImmutable
    {
        return $this->rawTimestampInTimezone('ends_at', $timezone);
    }

    private function rawTimestampInTimezone(string $column, ?string $timezone): CarbonImmutable
    {
        $raw = $this->getRawOriginal($column);
        $value = $raw !== null ? CarbonImmutable::parse($raw, 'UTC') : CarbonImmutable::instance($this->{$column});

        return $value->setTimezone($timezone ?: $this->timezone);
    }
}
