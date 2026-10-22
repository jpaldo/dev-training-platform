<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;

class CoachDashboardPageService
{
    private const STAT_LABELS = [
        'participants' => 'PARTICIPANTS',
        'completed' => 'COMPLETED',
        'needs_review' => 'NEEDS REVIEW',
        'needs_revision' => 'NEEDS REVISION',
        'at_risk' => 'AT RISK',
    ];

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the coach dashboard renders: the headline counts with their labels, the mentor booking
     * calendar link and the coach tool links (plus Users and Settings · Google Drive for admins). The page has
     * no single main action, so every link is secondary.
     *
     * @return array{stats: array<string, array{label: string, value: int}>, calendarUrl: string, toolLinks: list<array{label: string, url: string}>}
     */
    public function showData(User $viewer): array
    {
        $stats = [];

        foreach ($this->reports->dashboardStats() as $key => $value) {
            $stats[$key] = ['label' => self::STAT_LABELS[$key], 'value' => $value];
        }

        return [
            'stats' => $stats,
            'calendarUrl' => route('coach.mentor-booking-calendar.index'),
            'toolLinks' => $this->toolLinks($viewer),
        ];
    }

    /**
     * The coach tool links in display order; the Users and Google Drive settings links are for admins only.
     *
     * @return list<array{label: string, url: string}>
     */
    private function toolLinks(User $viewer): array
    {
        $links = [
            ['label' => 'Participants', 'url' => route('coach.participants')],
            ['label' => 'Review queue', 'url' => route('coach.reviews')],
            ['label' => 'Recovery Requests', 'url' => route('coach.recovery.index')],
            ['label' => 'Gates', 'url' => route('coach.gates')],
            ['label' => 'Reports', 'url' => route('coach.reports')],
            ['label' => 'Attendance', 'url' => route('coach.attendance.index')],
            ['label' => 'Scoring', 'url' => route('coach.scoring.index')],
        ];

        if ($viewer->hasAnyRole(UserRole::Admin)) {
            $links[] = ['label' => 'Users', 'url' => route('admin.users.index')];
            $links[] = ['label' => 'Settings · Google Drive', 'url' => route('admin.settings.google-drive')];
        }

        return $links;
    }
}
