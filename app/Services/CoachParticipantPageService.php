<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\User;
use App\Models\WeekFourPeerInterviewWaiver;
use App\Models\WeeklyVideoRecording;
use Illuminate\Support\Collection;

class CoachParticipantPageService
{
    public function __construct(
        private readonly CoachGateOverviewService $gateOverview,
        private readonly WeekFourInterviewService $interviews,
    ) {}

    /**
     * Everything the coach participant page renders: the viewer's access level, the submission and weekly video rows,
     * the Gate rows, the daily log and attendance summary, the Week 4 peer interview waiver and the page tabs.
     *
     * @return array{participant: User, access: string, canManage: bool, submissions: Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion}>, videoRecordings: Collection<int, WeeklyVideoRecording>, gates: Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, daily_log_count: int}>, attendance: array{completed_sessions: int, total_hours: float, active_session: ?AttendanceSession}, weekFourActivity: ?Activity, peerWaiver: ?WeekFourPeerInterviewWaiver, tabs: list<array{key: string, label: string}>, selectedTab: string}
     */
    public function showData(User $viewer, User $participant): array
    {
        $participant->load(['activitySubmissions.activity.week', 'activitySubmissions.versions', 'weeklyVideoRecordings.week', 'attendanceSessions', 'assignedCoaches'])
            ->loadCount('dailyLogs');
        $canManage = $viewer->canManageParticipant($participant);
        $weekFourActivity = $this->interviews->peerInterviewActivity();

        return [
            'participant' => $participant,
            'access' => match (true) {
                $viewer->hasAnyRole(UserRole::Admin) => 'admin',
                $canManage => 'manage',
                default => 'read_only',
            },
            'canManage' => $canManage,
            'submissions' => $this->submissionRows($participant),
            'videoRecordings' => $participant->weeklyVideoRecordings->sortByDesc('uploaded_at')->values(),
            'gates' => $this->gateRows($participant),
            'attendance' => $this->attendanceSummary($participant),
            'weekFourActivity' => $weekFourActivity,
            'peerWaiver' => $weekFourActivity ? $this->interviews->waiverFor($participant, $weekFourActivity) : null,
            'tabs' => array_values(array_filter([
                ['key' => 'submissions', 'label' => 'Submissions'],
                ['key' => 'gates', 'label' => 'Gates'],
                ['key' => 'logs-attendance', 'label' => 'Logs & Attendance'],
                $weekFourActivity ? ['key' => 'week-four', 'label' => 'Week 4'] : null,
            ])),
            'selectedTab' => 'submissions',
        ];
    }

    /**
     * The participant's attempts with their status label and tone, version count and latest version.
     *
     * @return Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion}>
     */
    private function submissionRows(User $participant): Collection
    {
        return $participant->activitySubmissions->toBase()->map(fn (ActivitySubmission $submission): array => [
            'submission' => $submission,
            'status_label' => strtoupper(str_replace('_', ' ', $submission->status)),
            'tone' => match ($submission->status) {
                'failed' => 'danger',
                'draft', 'needs_revision' => 'accent',
                'approved', 'completed' => 'success',
                default => 'neutral',
            },
            'version_count' => $submission->versions->count(),
            'latest_version' => $submission->versions->sortByDesc('version_number')->first(),
        ]);
    }

    /**
     * The participant's Week 2–4 Gates with their evaluation or readiness status, blocker count and daily log count.
     *
     * @return Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, daily_log_count: int}>
     */
    private function gateRows(User $participant): Collection
    {
        ['gateRows' => $gateRows, 'weeklyLogCounts' => $weeklyLogCounts] = $this->gateOverview->participantOverview($participant);

        return $gateRows->map(function (array $row) use ($weeklyLogCounts): array {
            $status = $row['evaluation']?->status ?? GateEvaluationStatus::fromReadiness($row['readiness']);

            return [
                'gate' => $row['gate'],
                'status_label' => $status->label(),
                'tone' => match ($status) {
                    GateEvaluationStatus::Passed => 'success',
                    GateEvaluationStatus::Failed => 'danger',
                    GateEvaluationStatus::NeedsRevision => 'warning',
                    GateEvaluationStatus::ReadyForReview, GateEvaluationStatus::AwaitingReview => 'accent',
                    GateEvaluationStatus::NotReady => 'neutral',
                },
                'blocker_count' => count($row['readiness'] ?? []),
                'daily_log_count' => $weeklyLogCounts[$row['gate']->week->id] ?? 0,
            ];
        });
    }

    /**
     * The completed attendance sessions and their total hours, and the session still checked in, if any.
     *
     * @return array{completed_sessions: int, total_hours: float, active_session: ?AttendanceSession}
     */
    private function attendanceSummary(User $participant): array
    {
        $completedSessions = $participant->attendanceSessions->whereNotNull('check_out_at');

        return [
            'completed_sessions' => $completedSessions->count(),
            'total_hours' => (float) $completedSessions->sum(fn (AttendanceSession $session): float => $session->totalHours()),
            'active_session' => $participant->attendanceSessions->firstWhere('check_out_at', null),
        ];
    }
}
