<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

class SidebarNavigationService
{
    public function __construct(private ParticipantNotificationService $notifications) {}

    /**
     * Everything the authenticated layout's sidebar renders: the user's link groups in order (coaches get Overview,
     * People, Review and Resources, admins also Admin, participants Training and Resources), each link with its URL,
     * whether the current route belongs to it and its badge count (the participant's unread notifications), then the
     * signed-in user's name, role label and Profile link.
     *
     * @return array{groups: list<array{label: string, links: list<array{label: string, url: string, is_active: bool, count: int}>}>, user_name: string, role_label: string, profile: array{url: string, is_active: bool}}
     */
    public function sidebarData(User $user, Request $request): array
    {
        return [
            'groups' => collect($this->groupDefinitions($user))
                ->map(fn (array $links, string $groupLabel): array => [
                    'label' => $groupLabel,
                    'links' => array_map(fn (array $link): array => [
                        'label' => $link['label'],
                        'url' => route($link['route']),
                        'is_active' => $request->routeIs(...$link['active']),
                        'count' => $link['count'] ?? 0,
                    ], $links),
                ])
                ->values()
                ->all(),
            'user_name' => $user->name,
            'role_label' => $user->role->label(),
            'profile' => [
                'url' => route('profile.show'),
                'is_active' => $request->routeIs('profile.*'),
            ],
        ];
    }

    /**
     * The guest layout's account links (Login, Register), each with its URL and whether it is the current page.
     *
     * @return list<array{label: string, url: string, is_current: bool}>
     */
    public function guestLinks(Request $request): array
    {
        return array_map(fn (string $route): array => [
            'label' => ucfirst($route),
            'url' => route($route),
            'is_current' => $request->routeIs($route),
        ], ['login', 'register']);
    }

    /**
     * The sidebar links per group label, in display order: each link's route name, the route patterns that mark it
     * as the current page and an optional badge count.
     *
     * @return array<string, list<array{label: string, route: string, active: list<string>, count?: int}>>
     */
    private function groupDefinitions(User $user): array
    {
        if (! $user->isCoachOrAdmin()) {
            return [
                'Training' => [
                    ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => ['dashboard', 'training.sessions.*', 'training.weeks.*', 'training.activities.*', 'training.submission.*', 'training.gates.*', 'training.video-recording.*']],
                    ['label' => 'Attendance', 'route' => 'participant.attendance.index', 'active' => ['participant.attendance.*']],
                    ['label' => 'Notifications', 'route' => 'participant.notifications', 'active' => ['participant.notifications'], 'count' => $this->notifications->unreadCount($user)],
                ],
            ];
        }

        $groups = [
            'Overview' => [
                ['label' => 'Mentor Dashboard', 'route' => 'coach.dashboard', 'active' => ['coach.dashboard']],
            ],
            'People' => [
                ['label' => 'Participants', 'route' => 'coach.participants', 'active' => ['coach.participants', 'coach.participants.*']],
                ['label' => 'Attendance', 'route' => 'coach.attendance.index', 'active' => ['coach.attendance.*']],
            ],
            'Review' => [
                ['label' => 'Reviews', 'route' => 'coach.reviews', 'active' => ['coach.reviews', 'coach.reviews.*']],
                ['label' => 'Gates', 'route' => 'coach.gates', 'active' => ['coach.gates', 'training.coach.gates.*']],
                ['label' => 'Scoring', 'route' => 'coach.scoring.index', 'active' => ['coach.scoring.*']],
                ['label' => 'Recovery Requests', 'route' => 'coach.recovery.index', 'active' => ['coach.recovery.*']],
                ['label' => 'Reports', 'route' => 'coach.reports', 'active' => ['coach.reports', 'coach.reports.*']],
            ],
        ];

        if ($user->hasAnyRole(UserRole::Admin)) {
            $groups['Admin'] = [
                ['label' => 'Admin Dashboard', 'route' => 'admin.dashboard', 'active' => ['admin.dashboard']],
                ['label' => 'Training Sessions', 'route' => 'admin.sessions.index', 'active' => ['admin.sessions.*']],
                ['label' => 'Users / Participants', 'route' => 'admin.users.index', 'active' => ['admin.users.*']],
                ['label' => 'Settings', 'route' => 'admin.settings.index', 'active' => ['admin.settings.index', 'admin.settings.email', 'admin.settings.google', 'admin.settings.interview-scheduling', 'admin.settings.training']],
                ['label' => 'Google Drive', 'route' => 'admin.settings.google-drive', 'active' => ['admin.settings.google-drive']],
                ['label' => 'AI', 'route' => 'admin.settings.ai', 'active' => ['admin.settings.ai']],
            ];
        }

        return $groups;
    }
}
