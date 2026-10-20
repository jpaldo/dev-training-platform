<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarService
{
    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function __construct(private readonly GoogleDriveConnectionService $connections) {}

    /** @return array{event_id:string,meet_url:string,conference_id:?string} */
    public function create(GoogleDriveConnection $connection, string $summary, string $description, string $start, string $end, string $timezone, array $attendees, string $requestId): array
    {
        $body = [
            'summary' => $summary,
            'description' => $description,
            'start' => ['dateTime' => $start, 'timeZone' => $timezone],
            'end' => ['dateTime' => $end, 'timeZone' => $timezone],
            'attendees' => array_map(fn (string $email): array => ['email' => $email], array_values(array_unique($attendees))),
            'conferenceData' => ['createRequest' => ['requestId' => $requestId, 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
        ];
        $response = Http::withToken($this->connections->accessToken($connection))->acceptJson()->post('https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=all', $body);
        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar could not create the meeting.');
        }
        $eventId = $response->json('id');
        $entryPoint = collect($response->json('conferenceData.entryPoints', []))->firstWhere('entryPointType', 'video');
        $meetUrl = $response->json('hangoutLink') ?: ($entryPoint['uri'] ?? null);
        if (blank($eventId) || blank($meetUrl)) {
            throw new RuntimeException('Google Calendar did not return a Meet conference.');
        }

        return ['event_id' => $eventId, 'meet_url' => $meetUrl, 'conference_id' => $response->json('conferenceData.conferenceId')];
    }

    /** @return array{event_id:string,meet_url:?string,conference_id:?string} */
    public function update(GoogleDriveConnection $connection, string $eventId, string $summary, string $description, string $start, string $end, string $timezone, array $attendees, ?string $existingMeetUrl = null, ?string $existingConferenceId = null): array
    {
        $body = ['summary' => $summary, 'description' => $description, 'start' => ['dateTime' => $start, 'timeZone' => $timezone], 'end' => ['dateTime' => $end, 'timeZone' => $timezone], 'attendees' => array_map(fn (string $email): array => ['email' => $email], array_values(array_unique($attendees)))];
        $response = Http::withToken($this->connections->accessToken($connection))->acceptJson()->patch("https://www.googleapis.com/calendar/v3/calendars/primary/events/{$eventId}?sendUpdates=all", $body);
        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar could not update the meeting.');
        }

        return ['event_id' => $eventId, 'meet_url' => $response->json('hangoutLink') ?: $existingMeetUrl, 'conference_id' => $response->json('conferenceData.conferenceId') ?: $existingConferenceId];
    }

    public function delete(GoogleDriveConnection $connection, string $eventId): void
    {
        $response = Http::withToken($this->connections->accessToken($connection))->acceptJson()->delete("https://www.googleapis.com/calendar/v3/calendars/primary/events/{$eventId}?sendUpdates=all");
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Google Calendar could not cancel the meeting.');
        }
    }
}
