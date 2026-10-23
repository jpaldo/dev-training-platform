<?php

namespace Tests\Feature;

use App\Models\ParticipantNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParticipantNotificationsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_notifications_page_lists_notifications_newest_first_with_a_single_mark_all_as_read_main_action(): void
    {
        $participant = $this->participantWithoutNotifications();
        $revision = $this->notification($participant, [
            'type' => 'needs_revision',
            'title' => 'Revision required',
            'body' => 'Mentor feedback is available.',
            'action_url' => '/training/revise',
            'created_at' => now()->subMinutes(5),
        ]);
        $reminder = $this->notification($participant, [
            'title' => 'Activity incomplete',
            'body' => 'Complete your activity.',
            'action_url' => '/dashboard',
            'created_at' => now()->subHour(),
        ]);
        $this->notification($participant, [
            'title' => 'Week 2 completed',
            'body' => 'Your mentor marked Week 2 completed.',
            'action_url' => null,
            'is_read' => true,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($participant)->get(route('participant.notifications'))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANT NOTIFICATIONS',
                'Notifications',
                'Your current training reminders and important updates.',
                'id="notifications-main-action"',
                'action="'.route('participant.notifications.read-all').'"',
                'Mark All as Read',
                'aria-label="Filter notifications"',
                'href="'.route('participant.notifications', ['filter' => 'all']).'" aria-current="page"',
                'href="'.route('participant.notifications', ['filter' => 'unread']).'"',
                'href="'.route('participant.notifications', ['filter' => 'read']).'"',
                'Revision required',
                'Unread',
                '5 minutes ago',
                'Mentor feedback is available.',
                'href="/training/revise"',
                'View Feedback &amp; Revise',
                'action="'.route('participant.notifications.read', $revision).'"',
                'Mark as Read',
                'Activity incomplete',
                'Unread',
                '1 hour ago',
                'href="/dashboard"',
                '>Open<',
                'action="'.route('participant.notifications.read', $reminder).'"',
                'Mark as Read',
                'Week 2 completed',
                '1 day ago',
                'Your mentor marked Week 2 completed.',
            ], false)
            ->assertDontSee('No notifications right now.');

        $content = $response->getContent();
        $this->assertSame(3, substr_count($content, 'data-notification-row'));
        $this->assertSame(1, substr_count($content, 'Mark All as Read'));
        $this->assertSame(2, substr_count($content, 'Mark as Read'));
        $this->assertSame(2, substr_count($content, 'Unread</span>'));
        $this->assertSame(2, substr_count($content, 'aria-current="false"'));
    }

    public function test_filters_show_only_unread_or_read_notifications_and_mark_the_active_filter(): void
    {
        $participant = $this->participantWithoutNotifications();
        $this->notification($participant, ['title' => 'Open reminder']);
        $this->notification($participant, ['title' => 'Seen update', 'is_read' => true]);
        $this->notification($participant, ['title' => 'Resolved reminder', 'resolved_at' => now()]);

        $this->actingAs($participant)->get(route('participant.notifications', ['filter' => 'unread']))
            ->assertOk()
            ->assertSee('href="'.route('participant.notifications', ['filter' => 'unread']).'" aria-current="page"', false)
            ->assertSee('Open reminder')
            ->assertDontSee('Seen update')
            ->assertDontSee('Resolved reminder');

        $this->actingAs($participant)->get(route('participant.notifications', ['filter' => 'read']))
            ->assertOk()
            ->assertSee('href="'.route('participant.notifications', ['filter' => 'read']).'" aria-current="page"', false)
            ->assertSee('Seen update')
            ->assertDontSee('Open reminder')
            ->assertDontSee('Resolved reminder');

        $this->actingAs($participant)->get(route('participant.notifications'))
            ->assertOk()
            ->assertSee('Open reminder')
            ->assertSee('Seen update')
            ->assertSee('Resolved reminder');
    }

    public function test_notifications_page_without_notifications_shows_the_empty_state_and_no_main_action(): void
    {
        $participant = $this->participantWithoutNotifications();

        $response = $this->actingAs($participant)->get(route('participant.notifications'))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Filter notifications"',
                'No notifications right now.',
            ], false)
            ->assertDontSee('notifications-main-action')
            ->assertDontSee('Mark All as Read');

        $this->assertStringNotContainsString('data-notification-row', $response->getContent());
    }

    public function test_mark_all_as_read_is_offered_only_while_a_notification_is_unread(): void
    {
        $participant = $this->participantWithoutNotifications();
        $this->notification($participant, ['title' => 'Seen update', 'is_read' => true]);

        $this->actingAs($participant)->get(route('participant.notifications'))
            ->assertOk()
            ->assertSee('Seen update')
            ->assertDontSee('Mark All as Read');

        $this->notification($participant, ['title' => 'Resolved reminder', 'resolved_at' => now()]);

        $this->actingAs($participant)->get(route('participant.notifications'))
            ->assertOk()
            ->assertSee('id="notifications-main-action"', false)
            ->assertSee('Mark All as Read');
    }

    public function test_marking_all_as_read_also_marks_resolved_notifications_as_read(): void
    {
        $participant = $this->participantWithoutNotifications();
        $open = $this->notification($participant, ['title' => 'Open reminder']);
        $resolved = $this->notification($participant, ['title' => 'Resolved reminder', 'resolved_at' => now()]);
        $otherParticipantNotification = $this->notification($this->participantWithoutNotifications(), ['title' => 'Someone else']);

        $response = $this->actingAs($participant)
            ->from(route('participant.notifications'))
            ->followingRedirects()
            ->post(route('participant.notifications.read-all'))
            ->assertOk()
            ->assertSee('Resolved reminder')
            ->assertDontSee('Mark All as Read');

        $this->assertStringNotContainsString('Unread</span>', $response->getContent());
        $this->assertStringNotContainsString('Mark as Read', $response->getContent());
        $this->assertTrue($open->fresh()->is_read);
        $this->assertTrue($resolved->fresh()->is_read);
        $this->assertFalse($otherParticipantNotification->fresh()->is_read);
    }

    public function test_marking_all_as_read_returns_to_the_page_with_the_status_alert(): void
    {
        $participant = $this->participantWithoutNotifications();
        $this->notification($participant, ['title' => 'Open reminder']);

        $response = $this->actingAs($participant)
            ->from(route('participant.notifications'))
            ->followingRedirects()
            ->post(route('participant.notifications.read-all'))
            ->assertOk()
            ->assertSeeInOrder(['role="status"', 'All notifications marked as read.', 'Open reminder'], false);

        $this->assertStringNotContainsString('Unread</span>', $response->getContent());
        $this->assertStringNotContainsString('Mark as Read', $response->getContent());
    }

    public function test_notifications_page_query_count_does_not_grow_with_notifications(): void
    {
        $this->assertSame($this->notificationsPageQueryCount(1), $this->notificationsPageQueryCount(3));
    }

    private function notificationsPageQueryCount(int $notifications): int
    {
        $participant = $this->participantWithoutNotifications();
        foreach (range(1, $notifications) as $index) {
            $this->notification($participant, ['is_read' => $index % 2 === 0]);
        }
        $url = route('participant.notifications');

        $this->actingAs($participant)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($participant)->get($url)->assertOk();
        DB::disableQueryLog();
        $this->assertSame($notifications, substr_count($response->getContent(), 'data-notification-row'));

        return count(DB::getQueryLog());
    }

    private function participantWithoutNotifications(): User
    {
        $participant = User::factory()->create();
        $participant->participantNotifications()->delete();

        return $participant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function notification(User $participant, array $attributes = []): ParticipantNotification
    {
        return ParticipantNotification::factory()->create(['user_id' => $participant->id, ...$attributes]);
    }
}
