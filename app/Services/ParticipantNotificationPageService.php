<?php

namespace App\Services;

use App\Enums\ParticipantNotificationType;
use App\Models\ParticipantNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ParticipantNotificationPageService
{
    private const NOTIFICATIONS_PER_PAGE = 25;

    private const FILTERS = ['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'];

    /**
     * Everything the participant's notifications page renders: the All / Unread / Read filter links and the
     * participant's notifications for the selected filter, newest first. The main action is Mark All as Read.
     *
     * @return array{filters: list<array{label: string, url: string, is_active: bool}>, notifications: LengthAwarePaginator<int, array{title: string, body: string, created_at: string, created_at_label: string, is_read: bool, action_url: ?string, action_label: string, read_url: string}>, readAllUrl: string}
     */
    public function indexData(User $participant, string $filter): array
    {
        return [
            'filters' => collect(self::FILTERS)
                ->map(fn (string $label, string $key): array => [
                    'label' => $label,
                    'url' => route('participant.notifications', ['filter' => $key]),
                    'is_active' => $key === $filter,
                ])
                ->values()
                ->all(),
            'notifications' => $participant->participantNotifications()
                ->when($filter === 'unread', fn (Builder $query): Builder => $query->unread())
                ->when($filter === 'read', fn (Builder $query): Builder => $query->where('is_read', true))
                ->latest()
                ->paginate(self::NOTIFICATIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ParticipantNotification $notification): array => [
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'created_at' => $notification->created_at->toIso8601String(),
                    'created_at_label' => $notification->created_at->diffForHumans(),
                    'is_read' => $notification->is_read,
                    'action_url' => $notification->action_url,
                    'action_label' => $notification->type === ParticipantNotificationType::NeedsRevision ? 'View Feedback & Revise' : 'Open',
                    'read_url' => route('participant.notifications.read', $notification),
                ]),
            'readAllUrl' => route('participant.notifications.read-all'),
        ];
    }
}
