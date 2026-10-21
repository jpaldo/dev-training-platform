<?php

namespace App\Services;

use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use Illuminate\Database\Eloquent\Builder;

class AdminDashboardPageService
{
    public function __construct(private readonly AdminSettingsStatusService $settings) {}

    /**
     * Everything the admin dashboard renders: the account counts, the training, review / Gate and Week 4 counts
     * with their labels, the integration statuses and the admin tool links. The page has no single main action,
     * so every link is secondary.
     *
     * @return array{systemStats: list<array{label: string, value: int}>, trainingStats: list<array{label: string, value: int}>, reviewStats: list<array{label: string, value: int}>, weekFourStats: list<array{label: string, value: int}>, integrationStatuses: list<array{label: string, status: string}>, toolLinks: list<array{label: string, url: string}>}
     */
    public function showData(): array
    {
        $participantCount = User::query()->where('role', 'participant')->count();
        $program = Program::query()->where('is_active', true)->first();
        $pairedParticipantCount = WeekFourInterviewPair::query()->where('status', 'active')->count() * 2;

        return [
            'systemStats' => [
                ['label' => 'PARTICIPANTS', 'value' => $participantCount],
                ['label' => 'COACHES', 'value' => User::query()->where('role', 'coach')->count()],
                ['label' => 'ADMINS', 'value' => User::query()->where('role', 'admin')->count()],
            ],
            'trainingStats' => [
                ['label' => 'GENERAL', 'value' => User::query()->where('role', 'participant')->whereHas('dailyLogs')->count()],
                ['label' => 'WEEK2', 'value' => $this->participantsWithWeek($program, 2)],
                ['label' => 'WEEK3', 'value' => $this->participantsWithWeek($program, 3)],
                ['label' => 'WEEK4', 'value' => $this->participantsWithWeek($program, 4)],
            ],
            'reviewStats' => [
                ['label' => 'AWAITING REVIEW', 'value' => ActivitySubmission::query()->whereIn('status', ['submitted', 'resubmitted', 'under_review'])->count()],
                ['label' => 'NEEDS REVISION', 'value' => ActivitySubmission::query()->where('status', 'needs_revision')->count()],
                ['label' => 'PENDING GATES', 'value' => GateEvaluation::query()->whereNotIn('status', ['passed'])->count()],
            ],
            'weekFourStats' => [
                ['label' => 'PARTNER ASSIGNED', 'value' => $pairedParticipantCount],
                ['label' => 'WAITING FOR PARTNER', 'value' => max(0, $participantCount - $pairedParticipantCount)],
                ['label' => 'MENTOR INTERVIEWS SCHEDULED', 'value' => WeekFourMentorInterviewSchedule::query()->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->count()],
                ['label' => 'CALENDAR ATTENTION', 'value' => WeekFourMentorInterviewSchedule::query()->whereIn('status', [WeekFourMentorInterviewSchedule::FAILED, WeekFourMentorInterviewSchedule::PENDING])->count()],
            ],
            'integrationStatuses' => [
                ['label' => 'Google:', 'status' => $this->settings->google()['calendar_status']],
                ['label' => 'Email:', 'status' => $this->settings->email()['status']],
                ['label' => 'AI:', 'status' => $this->settings->ai()['status']],
                ['label' => 'Queue:', 'status' => 'Application queue configuration available; detailed worker health remains on the existing operations tooling.'],
            ],
            'toolLinks' => [
                ['label' => 'Manage Participants', 'url' => route('admin.users.index')],
                ['label' => 'Coach Reviews', 'url' => route('coach.reviews')],
                ['label' => 'Manage Gates', 'url' => route('coach.gates')],
                ['label' => 'Reports', 'url' => route('coach.reports')],
                ['label' => 'Reusable Asset Library', 'url' => route('training.reusable-assets.index')],
                ['label' => 'Coach Tools', 'url' => route('coach.dashboard')],
                ['label' => 'Settings', 'url' => route('admin.settings.index')],
            ],
        ];
    }

    /**
     * The number of participants with at least one activity submission in the given week of the active program
     * (0 without an active program or without that week).
     */
    private function participantsWithWeek(?Program $program, int $weekNumber): int
    {
        if (! $program) {
            return 0;
        }

        $week = $program->weeks()->where('week_number', $weekNumber)->first();

        if (! $week) {
            return 0;
        }

        return User::query()
            ->where('role', 'participant')
            ->whereHas('activitySubmissions', fn (Builder $submissions): Builder => $submissions->whereHas('activity', fn (Builder $activity): Builder => $activity->where('training_week_id', $week->id)))
            ->count();
    }
}
