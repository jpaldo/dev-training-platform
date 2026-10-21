<?php

namespace App\Services;

use App\Enums\MentorInterviewAvailabilityStatus;
use App\Models\MentorInterviewAvailability;
use App\Models\User;
use App\Models\WeekFourMentorInterviewSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MentorInterviewAvailabilityService
{
    public function listForMentor(User $mentor, bool $includeClosed = true): Collection
    {
        return MentorInterviewAvailability::query()->where('mentor_id', $mentor->id)->when(! $includeClosed, fn (Builder $query): Builder => $query->where('status', MentorInterviewAvailabilityStatus::Open))->orderBy('available_date')->orderBy('start_time')->get();
    }

    public function create(User $mentor, array $data): MentorInterviewAvailability
    {
        $this->authorizeMentor($mentor);
        $availability = new MentorInterviewAvailability;
        $availability->fill($data + ['mentor_id' => $mentor->id, 'slot_duration_minutes' => (int) config('training.week_four_mentor_interview_duration_minutes', 60), 'capacity' => 1, 'status' => MentorInterviewAvailabilityStatus::Open]);
        $this->validateWindow($availability);
        $this->ensureNoOverlap($availability);
        $availability->save();

        return $availability;
    }

    public function update(User $actor, MentorInterviewAvailability $availability, array $data): MentorInterviewAvailability
    {
        $this->authorizeAccess($actor, $availability);
        $availability->fill($data);
        $this->validateWindow($availability);
        $booked = $this->bookedCount($availability);
        if ((int) $availability->capacity < $booked) {
            throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than confirmed bookings.']);
        }
        $this->ensureNoOverlap($availability);
        $availability->save();

        return $availability;
    }

    public function close(User $actor, MentorInterviewAvailability $availability): void
    {
        $this->authorizeAccess($actor, $availability);
        $availability->update(['status' => MentorInterviewAvailabilityStatus::Closed]);
    }

    /** @return Collection<int, array<string,mixed>> */
    public function futureSlotsForParticipant(User $participant): Collection
    {
        $mentor = app(MentorInterviewSchedulingService::class)->assignedMentor($participant);
        if (! $mentor) {
            return collect();
        }

        return $this->futureSlotsForMentor($mentor);
    }

    /** @return Collection<int, array<string,mixed>> */
    public function futureSlotsForMentor(User $mentor): Collection
    {
        $timezone = $this->timezone();
        $now = CarbonImmutable::now($timezone);

        return $this->listForMentor($mentor, false)->flatMap(function (MentorInterviewAvailability $availability) use ($now, $timezone): array {
            return collect($availability->slots($timezone))->map(function (array $slot) use ($availability, $now): array {
                $booked = $this->bookedCountForSlot($availability, $slot['starts_at'], $slot['ends_at']);

                return ['availability' => $availability, 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'booked' => $booked, 'remaining' => max(0, (int) $availability->capacity - $booked), 'status' => $slot['ends_at']->isPast() ? 'past' : ($booked >= (int) $availability->capacity ? 'full' : ($slot['starts_at']->lt($now) ? 'past' : 'available'))];
            })->all();
        })->filter(fn (array $slot): bool => $slot['status'] !== 'past')->values();
    }

    public function slot(MentorInterviewAvailability $availability, string $slotStart): array
    {
        $timezone = $this->timezone();
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slotStart, $timezone);
        } catch (\Throwable) {
            $start = false;
        }
        if (! $start) {
            throw ValidationException::withMessages(['slot_start' => 'Please choose an available interview slot.']);
        }
        foreach ($availability->slots($timezone) as $slot) {
            if ($slot['starts_at']->equalTo($start)) {
                return $slot;
            }
        }
        throw ValidationException::withMessages(['slot_start' => 'Please choose an available interview slot.']);
    }

    public function reserve(User $participant, MentorInterviewAvailability $availability, string $slotStart): array
    {
        return DB::transaction(function () use ($availability, $slotStart): array {
            $locked = MentorInterviewAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['slot_start' => 'This interview availability is closed.']);
            }
            $slot = $this->slot($locked, $slotStart);
            if ($slot['starts_at']->isPast()) {
                throw ValidationException::withMessages(['slot_start' => 'This interview slot has already passed.']);
            }
            $booked = $this->bookedCountForSlot($locked, $slot['starts_at'], $slot['ends_at']);
            if ($booked >= (int) $locked->capacity) {
                throw ValidationException::withMessages(['slot_start' => 'This interview slot is no longer available. Please choose another time.']);
            }

            return [$locked, $slot];
        });
    }

    private function bookedCount(MentorInterviewAvailability $availability): int
    {
        return $availability->schedules()->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->count();
    }

    private function bookedCountForSlot(MentorInterviewAvailability $availability, CarbonImmutable $startsAt, CarbonImmutable $endsAt): int
    {
        return WeekFourMentorInterviewSchedule::query()->where('availability_id', $availability->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->where('starts_at', $startsAt->utc())->where('ends_at', $endsAt->utc())->count();
    }

    private function validateWindow(MentorInterviewAvailability $availability): void
    {
        $timezone = $this->timezone();
        $date = CarbonImmutable::parse((string) $availability->available_date, $timezone);
        $start = CarbonImmutable::parse($date->format('Y-m-d').' '.$availability->start_time, $timezone);
        $end = CarbonImmutable::parse($date->format('Y-m-d').' '.$availability->end_time, $timezone);
        if ($date->isBefore(CarbonImmutable::today($timezone)) || $end->lte($start)) {
            throw ValidationException::withMessages(['available_date' => 'Choose a future date and a valid time range.']);
        }
        $duration = (int) $availability->slot_duration_minutes;
        $capacity = (int) $availability->capacity;
        if ($duration < 15 || $duration > 240 || $capacity < 1 || $capacity > 20) {
            throw ValidationException::withMessages(['capacity' => 'Capacity must be between 1 and 20 and slot duration must be between 15 and 240 minutes.']);
        }
        if ($start->addMinutes($duration)->gt($end)) {
            throw ValidationException::withMessages(['end_time' => 'The availability window must contain at least one complete slot.']);
        }
    }

    private function ensureNoOverlap(MentorInterviewAvailability $availability): void
    {
        $timezone = $this->timezone();
        $date = CarbonImmutable::parse((string) $availability->available_date, $timezone);
        $start = CarbonImmutable::parse($date->format('Y-m-d').' '.$availability->start_time, $timezone);
        $end = CarbonImmutable::parse($date->format('Y-m-d').' '.$availability->end_time, $timezone);
        $overlap = MentorInterviewAvailability::query()->where('mentor_id', $availability->mentor_id)->whereDate('available_date', $date->toDateString())->whereKeyNot($availability->id ?? 0)->where('status', MentorInterviewAvailabilityStatus::Open)->get()->contains(function (MentorInterviewAvailability $existing) use ($date, $start, $end, $timezone): bool {
            $existingStart = CarbonImmutable::parse($date->format('Y-m-d').' '.$existing->start_time, $timezone);
            $existingEnd = CarbonImmutable::parse($date->format('Y-m-d').' '.$existing->end_time, $timezone);

            return $existingStart->lt($end) && $existingEnd->gt($start);
        });
        if ($overlap) {
            throw ValidationException::withMessages(['start_time' => 'This availability overlaps another open schedule on the same date.']);
        }
    }

    private function authorizeMentor(User $mentor): void
    {
        abort_unless($mentor->isCoachOrAdmin(), 403);
    }

    private function authorizeAccess(User $actor, MentorInterviewAvailability $availability): void
    {
        abort_unless($actor->role === 'admin' || ($actor->role === 'coach' && $actor->id === $availability->mentor_id), 403);
    }

    private function timezone(): string
    {
        return (string) config('training.week_four_mentor_interview_timezone', config('app.timezone'));
    }
}
