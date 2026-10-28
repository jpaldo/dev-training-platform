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
    private const RECENT_PROGRAMS = 5;

    public function __construct(
        private readonly AdminSettingsStatusService $settings,
        private readonly CurriculumPageService $curriculumPages,
    ) {}

    /**
     * Everything the admin dashboard renders: the account counts, up to 5 curricula, the latest first week start first
     * and the unscheduled ones last, with their status, dates and participant count
     * (`CurriculumPageService::recentProgramRows()`), the links to the curricula list and the new curriculum form, the
     * review / Gate counts across every curriculum with their labels, the integration statuses and the admin tool
     * links. Training progress is counted per curriculum on its own page. The page has no single main action, so every
     * link is secondary.
     *
     * @return array{systemStats: list<array{label: string, value: int}>, recentPrograms: list<array{name: string, status_label: string, status_tone: string, dates: string, participants_count: int, url: string}>, programsUrl: string, newProgramUrl: string, reviewStats: list<array{label: string, value: int}>, integrationStatuses: list<array{label: string, status: string}>, toolLinks: list<array{label: string, url: string}>}
     */
    public function showData(): array
    {
        return [
            'systemStats' => [
                ['label' => 'PARTICIPANTS', 'value' => User::query()->where('role', UserRole::Participant)->count()],
                ['label' => 'MENTORS', 'value' => User::query()->where('role', UserRole::Coach)->count()],
                ['label' => 'ADMINS', 'value' => User::query()->where('role', UserRole::Admin)->count()],
            ],
            'recentPrograms' => $this->curriculumPages->recentProgramRows(self::RECENT_PROGRAMS),
            'programsUrl' => route('admin.programs.index'),
            'newProgramUrl' => route('admin.programs.create'),
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
