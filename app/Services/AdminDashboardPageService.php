<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\User;

class AdminDashboardPageService
{
    private const RECENT_SESSIONS = 5;

    public function __construct(
        private readonly AdminSettingsStatusService $settings,
        private readonly AdminTrainingSessionPageService $sessionPages,
    ) {}

    /**
     * Everything the admin dashboard renders: the account counts, the 5 training sessions with the latest start dates
     * (the session list's rows) with links to all sessions and to the create form, the review / Gate counts across every
     * program and session with their labels, the integration statuses and the admin tool links. Training progress is
     * counted per session on each session's dashboard, so this page reads no program. The page has no single main
     * action, so every link is secondary.
     *
     * @return array{systemStats: list<array{label: string, value: int}>, recentSessions: list<array{title: string, program: string, date_range: string, status_label: string, status_tone: string, participant_count: int, show_url: string, edit_url: string}>, sessionsUrl: string, createSessionUrl: string, reviewStats: list<array{label: string, value: int}>, integrationStatuses: list<array{label: string, status: string}>, toolLinks: list<array{label: string, url: string}>}
     */
    public function showData(): array
    {
        return [
            'systemStats' => [
                ['label' => 'PARTICIPANTS', 'value' => User::query()->where('role', UserRole::Participant)->count()],
                ['label' => 'MENTORS', 'value' => User::query()->where('role', UserRole::Coach)->count()],
                ['label' => 'ADMINS', 'value' => User::query()->where('role', UserRole::Admin)->count()],
            ],
            'recentSessions' => $this->sessionPages->recentSessionRows(self::RECENT_SESSIONS),
            'sessionsUrl' => route('admin.sessions.index'),
            'createSessionUrl' => route('admin.sessions.create'),
            'reviewStats' => [
                ['label' => 'AWAITING REVIEW', 'value' => ActivitySubmission::query()->whereIn('status', SubmissionStatus::awaitingReview())->count()],
                ['label' => 'NEEDS REVISION', 'value' => ActivitySubmission::query()->where('status', SubmissionStatus::NeedsRevision)->count()],
                ['label' => 'PENDING GATES', 'value' => GateEvaluation::query()->whereNotIn('status', [GateEvaluationStatus::Passed])->count()],
            ],
            'integrationStatuses' => [
                ['label' => 'Google:', 'status' => $this->settings->google()['status']],
                ['label' => 'Email:', 'status' => $this->settings->email()['status']],
                ['label' => 'AI:', 'status' => $this->settings->ai()['status']],
                ['label' => 'Queue:', 'status' => 'Application queue configuration available; detailed worker health remains on the existing operations tooling.'],
            ],
            'toolLinks' => [
                ['label' => 'Manage Participants', 'url' => route('admin.users.index')],
                ['label' => 'Mentor Reviews', 'url' => route('coach.reviews')],
                ['label' => 'Manage Gates', 'url' => route('coach.gates')],
                ['label' => 'Reports', 'url' => route('coach.reports')],
                ['label' => 'Mentor Tools', 'url' => route('coach.dashboard')],
                ['label' => 'Settings', 'url' => route('admin.settings.index')],
            ],
        ];
    }
}
