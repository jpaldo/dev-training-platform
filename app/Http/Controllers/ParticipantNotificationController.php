<?php

namespace App\Http\Controllers;

use App\Models\ParticipantNotification;
use App\Services\ParticipantNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantNotificationController extends Controller
{
    public function index(Request $request, ParticipantNotificationService $service): View
    {
        $user = $request->user();
        $service->sync($user);
        $filter = $request->validate(['filter' => ['nullable', 'in:all,unread,read']])['filter'] ?? 'all';
        $notifications = $user->participantNotifications()->when($filter === 'unread', fn ($q) => $q->unread())->when($filter === 'read', fn ($q) => $q->where('is_read', true))->latest()->paginate(25)->withQueryString();

        return view('participant.notifications', compact('notifications', 'filter'));
    }

    public function read(Request $request, ParticipantNotification $participantNotification): RedirectResponse
    {
        abort_unless($participantNotification->user_id === $request->user()->id, 404);
        $participantNotification->update(['is_read' => true]);

        return back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->participantNotifications()->unread()->update(['is_read' => true]);

        return back()->with('status', 'All notifications marked as read.');
    }
}
