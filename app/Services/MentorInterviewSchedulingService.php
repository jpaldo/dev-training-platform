<?php

namespace App\Services;

use App\Mail\MentorMockInterviewScheduled;
use App\Models\Activity;
use App\Models\MentorInterviewAvailability;
use App\Models\ParticipantNotification;
use App\Models\User;
use App\Models\WeekFourMentorInterviewSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class MentorInterviewSchedulingService
{
    public function __construct(private readonly GoogleCalendarService $calendar, private readonly WeekFourInterviewService $interviews, private readonly EmailConfigurationService $email, private readonly GoogleDriveConfigurationService $googleConfiguration) {}

    public function assignedMentor(User $participant): ?User
    {
        return $participant->assignedCoaches()->where('role', 'coach')->where('is_active', true)->orderBy('users.name')->first();
    }

    /**
     * The participant's latest pending or scheduled mentor interview for the activity, including schedules saved without an activity.
     */
    public function openScheduleFor(User $participant, Activity $activity): ?WeekFourMentorInterviewSchedule
    {
        return WeekFourMentorInterviewSchedule::query()
            ->where('participant_id', $participant->id)
            ->where(fn (Builder $query): Builder => $query->where('activity_id', $activity->id)->orWhereNull('activity_id'))
            ->whereIn('status', [WeekFourMentorInterviewSchedule::PENDING, WeekFourMentorInterviewSchedule::SCHEDULED])
            ->latest('id')
            ->first();
    }

    public function schedule(User $participant, Activity $activity, string $scheduledAt): WeekFourMentorInterviewSchedule
    {
        $this->ensureActivity($activity);
        $this->ensureParticipantCalendarEnabled();
        $mentor = $this->assignedMentor($participant);
        if (! $mentor) {
            throw ValidationException::withMessages(['mentor_schedule' => 'A QA Coach/Mentor must be assigned before you can schedule this interview.']);
        }
        $timezone = (string) config('training.week_four_mentor_interview_timezone', config('app.timezone'));
        $duration = (int) config('training.week_four_mentor_interview_duration_minutes', 60);
        $startsAt = $this->parseScheduledAt($scheduledAt, $timezone);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Choose a future date and time for the Mentor mock interview.']);
        }
        $endsAt = $startsAt->addMinutes($duration);
        $availability = $this->availabilityForSlot($mentor, $startsAt, $endsAt);
        if (! $availability) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Choose one of the future interview slots opened by your Mentor.']);
        }
        if (WeekFourMentorInterviewSchedule::query()->where('availability_id', $availability->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->where('starts_at', $startsAt->utc())->where('ends_at', $endsAt->utc())->count() >= (int) $availability->capacity) {
            throw ValidationException::withMessages(['mentor_schedule' => 'That interview slot is full. Please choose another time.']);
        }
        $existing = WeekFourMentorInterviewSchedule::query()->where('participant_id', $participant->id)->where('activity_id', $activity->id)->first();
        if ($existing?->status === WeekFourMentorInterviewSchedule::SCHEDULED) {
            return $existing->load(['participant', 'mentor']);
        }
        if (WeekFourMentorInterviewSchedule::query()->where('mentor_id', $mentor->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->where('starts_at', '<', $endsAt->utc())->where('ends_at', '>', $startsAt->utc())->where('participant_id', '!=', $participant->id)->exists()) {
            throw ValidationException::withMessages(['mentor_schedule' => 'That Mentor already has another scheduled mock interview at this time. Choose a different time.']);
        }

        $schedule = $existing ?: new WeekFourMentorInterviewSchedule;
        $schedule->fill(['participant_id' => $participant->id, 'mentor_id' => $mentor->id, 'activity_id' => $activity->id, 'availability_id' => $availability->id, 'starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'timezone' => $timezone, 'duration_minutes' => $duration, 'status' => WeekFourMentorInterviewSchedule::PENDING, 'failure_reason' => null]);
        $schedule->save();

        try {
            $connection = $participant->googleDriveConnection;
            if (! $connection || ! in_array(GoogleCalendarService::CALENDAR_SCOPE, $connection->scopes ?? [], true)) {
                throw ValidationException::withMessages(['mentor_schedule' => 'Google Calendar permission is required. Please reconnect Google Drive to grant Calendar access.']);
            }
            $event = $this->calendar->create($connection, "QA L3 Mock Interview - {$participant->name}", 'QA Level 3 Mentor mock interview.', $startsAt->format('c'), $endsAt->format('c'), $timezone, [$participant->email, $mentor->email], "qa-l3-{$schedule->id}");
            $schedule->update(['status' => WeekFourMentorInterviewSchedule::SCHEDULED, 'google_event_id' => $event['event_id'], 'google_meet_url' => $event['meet_url'], 'google_conference_id' => $event['conference_id'], 'scheduled_at' => now(), 'failure_reason' => null]);
            $schedule->load(['participant', 'mentor']);
            $this->queueNotifications($schedule, $participant, $mentor);
            ParticipantNotification::updateOrCreate(['user_id' => $participant->id, 'key' => "mentor-interview:{$schedule->id}"], ['type' => 'mentor_interview_scheduled', 'title' => 'Mock interview scheduled', 'body' => "Your mock interview with {$mentor->name} is scheduled for {$startsAt->format('F j')} at {$startsAt->format('g:i A')} ({$timezone}).", 'action_url' => route('training.submission.edit', [$activity->week, $activity]), 'priority' => 'high', 'resolved_at' => null]);

            return $schedule;
        } catch (ValidationException $exception) {
            $schedule->update(['status' => WeekFourMentorInterviewSchedule::FAILED, 'failure_reason' => 'Google Calendar permission is required.']);
            throw $exception;
        } catch (Throwable) {
            $schedule->update(['status' => WeekFourMentorInterviewSchedule::FAILED, 'failure_reason' => 'Google Calendar could not create the meeting.']);
            throw ValidationException::withMessages(['mentor_schedule' => 'We could not create your Google Calendar meeting yet. Please try again or reconnect Google Calendar.']);
        }
    }

    public function scheduleFromAvailability(User $participant, MentorInterviewAvailability $availability, string $slotStart): WeekFourMentorInterviewSchedule
    {
        $mentor = $this->assignedMentor($participant);
        abort_unless($mentor && $mentor->id === $availability->mentor_id, 403);
        $timezone = (string) config('training.week_four_mentor_interview_timezone', config('app.timezone'));
        $duration = (int) $availability->slot_duration_minutes;

        return DB::transaction(function () use ($participant, $availability, $slotStart, $mentor, $timezone, $duration): WeekFourMentorInterviewSchedule {
            $locked = MentorInterviewAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['slot_start' => 'This interview availability is closed.']);
            }
            $slot = app(MentorInterviewAvailabilityService::class)->slot($locked, $slotStart);
            $booked = WeekFourMentorInterviewSchedule::query()->where('availability_id', $locked->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->where('starts_at', $slot['starts_at']->utc())->where('ends_at', $slot['ends_at']->utc())->count();
            if ($slot['starts_at']->isPast() || $booked >= (int) $locked->capacity) {
                throw ValidationException::withMessages(['slot_start' => 'This interview slot is no longer available. Please choose another time.']);
            }
            $existing = WeekFourMentorInterviewSchedule::query()->where('participant_id', $participant->id)->whereIn('status', [WeekFourMentorInterviewSchedule::PENDING, WeekFourMentorInterviewSchedule::SCHEDULED])->first();
            if ($existing?->status === WeekFourMentorInterviewSchedule::SCHEDULED) {
                return $existing->load(['participant', 'mentor']);
            }
            $schedule = $existing ?: new WeekFourMentorInterviewSchedule;
            $schedule->fill(['participant_id' => $participant->id, 'mentor_id' => $mentor->id, 'activity_id' => null, 'availability_id' => $locked->id, 'starts_at' => $slot['starts_at']->utc(), 'ends_at' => $slot['ends_at']->utc(), 'timezone' => $timezone, 'duration_minutes' => $duration, 'status' => WeekFourMentorInterviewSchedule::PENDING, 'failure_reason' => null]);
            $schedule->save();

            try {
                $connection = $mentor->googleDriveConnection;
                if (! $connection || ! in_array(GoogleCalendarService::CALENDAR_SCOPE, $connection->scopes ?? [], true)) {
                    throw ValidationException::withMessages(['mentor_schedule' => "Online scheduling is temporarily unavailable because your Mentor's calendar has not been connected yet."]);
                }
                $event = $this->calendar->create($connection, "QA L3 Mock Interview - {$participant->name}", 'QA Level 3 Mentor mock interview.', $slot['starts_at']->format('c'), $slot['ends_at']->format('c'), $timezone, [$participant->email, $mentor->email], "qa-l3-{$schedule->id}");
                $schedule->update(['status' => WeekFourMentorInterviewSchedule::SCHEDULED, 'google_event_id' => $event['event_id'], 'google_meet_url' => $event['meet_url'], 'google_conference_id' => $event['conference_id'], 'scheduled_at' => now(), 'failure_reason' => null]);
                $schedule->load(['participant', 'mentor']);
                $this->queueNotifications($schedule, $participant, $mentor);

                return $schedule;
            } catch (ValidationException $exception) {
                $schedule->update(['status' => WeekFourMentorInterviewSchedule::FAILED, 'failure_reason' => 'Google Calendar permission is required.']);
                throw $exception;
            } catch (Throwable) {
                $schedule->update(['status' => WeekFourMentorInterviewSchedule::FAILED, 'failure_reason' => 'Google Calendar could not create the meeting.']);
                throw ValidationException::withMessages(['mentor_schedule' => 'We could not create your Google Calendar meeting yet. Please try again or reconnect Google Calendar.']);
            }
        });
    }

    public function reschedule(User $participant, Activity $activity, string $scheduledAt): WeekFourMentorInterviewSchedule
    {
        $this->ensureActivity($activity);
        $this->ensureParticipantCalendarEnabled();
        $schedule = WeekFourMentorInterviewSchedule::query()->where('participant_id', $participant->id)->where('activity_id', $activity->id)->firstOrFail();
        if ($schedule->status !== WeekFourMentorInterviewSchedule::SCHEDULED || blank($schedule->google_event_id)) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Schedule the Mentor mock interview before rescheduling it.']);
        }
        $mentor = $this->assignedMentor($participant);
        abort_unless($mentor && $mentor->id === $schedule->mentor_id, 403);
        $timezone = $schedule->timezone ?: (string) config('training.week_four_mentor_interview_timezone', config('app.timezone'));
        $startsAt = $this->parseScheduledAt($scheduledAt, $timezone);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Choose a future date and time for the Mentor mock interview.']);
        }
        $endsAt = $startsAt->addMinutes((int) $schedule->duration_minutes);
        $availability = $this->availabilityForSlot($mentor, $startsAt, $endsAt);
        if (! $availability) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Choose one of the future interview slots opened by your Mentor.']);
        }
        if (WeekFourMentorInterviewSchedule::query()->where('availability_id', $availability->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->where('id', '!=', $schedule->id)->where('starts_at', $startsAt->utc())->where('ends_at', $endsAt->utc())->count() >= (int) $availability->capacity) {
            throw ValidationException::withMessages(['mentor_schedule' => 'That interview slot is full. Please choose another time.']);
        }
        if (WeekFourMentorInterviewSchedule::query()->where('mentor_id', $mentor->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->where('id', '!=', $schedule->id)->where('starts_at', '<', $endsAt->utc())->where('ends_at', '>', $startsAt->utc())->exists()) {
            throw ValidationException::withMessages(['mentor_schedule' => 'That Mentor already has another scheduled mock interview at this time. Choose a different time.']);
        }
        $connection = $participant->googleDriveConnection;
        if (! $connection || ! in_array(GoogleCalendarService::CALENDAR_SCOPE, $connection->scopes ?? [], true)) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Google Calendar permission is required. Please reconnect Google Drive to grant Calendar access.']);
        }
        try {
            $event = $this->calendar->update($connection, $schedule->google_event_id, "QA L3 Mock Interview - {$participant->name}", 'QA Level 3 Mentor mock interview.', $startsAt->format('c'), $endsAt->format('c'), $timezone, [$participant->email, $mentor->email], $schedule->google_meet_url, $schedule->google_conference_id);
            $schedule->update(['availability_id' => $availability->id, 'starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'timezone' => $timezone, 'google_meet_url' => $event['meet_url'], 'google_conference_id' => $event['conference_id']]);
            $schedule->load(['participant', 'mentor']);
            $this->queueNotifications($schedule, $participant, $mentor);

            return $schedule;
        } catch (Throwable) {
            throw ValidationException::withMessages(['mentor_schedule' => 'We could not update your Google Calendar meeting yet. Please try again or reconnect Google Calendar.']);
        }
    }

    public function cancel(User $actor, WeekFourMentorInterviewSchedule $schedule): void
    {
        $allowed = $actor->id === $schedule->participant_id || ($actor->isCoachOrAdmin() && $actor->canManageParticipant($schedule->participant));
        abort_unless($allowed, 403);
        if ($schedule->status === WeekFourMentorInterviewSchedule::SCHEDULED && filled($schedule->google_event_id)) {
            $connection = $schedule->participant->googleDriveConnection;
            if ($connection) {
                $this->calendar->delete($connection, $schedule->google_event_id);
            }
        }
        $schedule->update(['status' => WeekFourMentorInterviewSchedule::CANCELLED, 'cancelled_at' => now()]);
    }

    private function ensureActivity(Activity $activity): void
    {
        if ($activity->week?->week_number !== 4 || $activity->position !== 2) {
            abort(404);
        }
    }

    private function ensureParticipantCalendarEnabled(): void
    {
        if (! $this->googleConfiguration->participantCalendarEnabled()) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Calendar / Google Meet scheduling is temporarily unavailable while the integration is being configured. Your Google Drive connection is not affected.']);
        }
    }

    private function queueNotifications(WeekFourMentorInterviewSchedule $schedule, User $participant, User $mentor): void
    {
        if (! $this->email->isMentorInterviewEmailEnabled()) {
            return;
        }

        Mail::to($participant->email)->queue(new MentorMockInterviewScheduled($schedule, $participant->name));
        Mail::to($mentor->email)->queue(new MentorMockInterviewScheduled($schedule, $mentor->name, true));
    }

    private function parseScheduledAt(string $scheduledAt, string $timezone): CarbonImmutable
    {
        try {
            $startsAt = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $scheduledAt, $timezone);
        } catch (Throwable) {
            $startsAt = false;
        }

        if (! $startsAt) {
            throw ValidationException::withMessages(['mentor_schedule' => 'Please select a valid interview date and time.']);
        }

        return $startsAt;
    }

    private function availabilityForSlot(User $mentor, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?MentorInterviewAvailability
    {
        return MentorInterviewAvailability::query()->where('mentor_id', $mentor->id)->whereDate('available_date', $startsAt->toDateString())->where('status', MentorInterviewAvailability::OPEN)->get()->first(function (MentorInterviewAvailability $availability) use ($startsAt, $endsAt): bool {
            return collect($availability->slots($startsAt->getTimezone()->getName()))->contains(fn (array $slot): bool => $slot['starts_at']->equalTo($startsAt) && $slot['ends_at']->equalTo($endsAt));
        });
    }
}
