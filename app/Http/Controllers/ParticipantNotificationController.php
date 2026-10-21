<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterParticipantNotificationsRequest;
use App\Models\ParticipantNotification;
use App\Models\User;
use App\Services\ParticipantNotificationPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantNotificationController extends Controller
{
    public function index(FilterParticipantNotificationsRequest $request, ParticipantNotificationPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('participant.notifications', $page->indexData($user, $request->validated('filter') ?? 'all'));
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
