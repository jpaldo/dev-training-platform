<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Support\Collection;

class CoachParticipantPageService
{
    public function __construct(
        private readonly CoachGateOverviewService $gateOverview,
        private readonly GoogleDriveConfigurationService $googleDrive,
    ) {}

    /**
     * Everything the coach participant page renders: the viewer's access level, the submission and weekly video rows,
     * the Gate rows, the attendance summary and the page tabs. `shareDomain` is the Google Workspace domain the
     * weekly videos are shared with (null when none is configured).
     *
     * @return array{participant: User, access: string, canManage: bool, submissions: Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion}>, videoRecordings: Collection<int, WeeklyVideoRecording>, shareDomain: ?string, gates: Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: ?string}>, attendance: array{completed_sessions: int, total_hours: float, active_session: ?AttendanceSession}, tabs: list<array{key: string, label: string}>, selectedTab: string}
     */
    public function showData(User $viewer, User $participant): array
    {
        $participant->load(['activitySubmissions.activity.week', 'activitySubmissions.versions', 'weeklyVideoRecordings.week', 'attendanceSessions', 'assignedCoaches']);
        $canManage = $viewer->canManageParticipant($participant);

        return [
            'participant' => $participant,
            'access' => match (true) {
                $viewer->hasAnyRole(UserRole::Admin) => 'admin',
                $canManage => 'manage',
                default => 'read_only',
            },
            'canManage' => $canManage,
            'submissions' => $this->submissionRows($participant),
            'videoRecordings' => $participant->weeklyVideoRecordings->sortByDesc('chosen_at')->values(),
            'shareDomain' => $this->googleDrive->getShareDomain(),
            'gates' => $this->gateRows($participant),
            'attendance' => $this->attendanceSummary($participant),
            'tabs' => [
                ['key' => 'submissions', 'label' => 'Submissions'],
                ['key' => 'gates', 'label' => 'Gates'],
                ['key' => 'attendance', 'label' => 'Attendance'],
            ],
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
            'status_label' => strtoupper($submission->status->label()),
            'tone' => match ($submission->status) {
                SubmissionStatus::Failed => 'danger',
                SubmissionStatus::Draft, SubmissionStatus::NeedsRevision => 'accent',
                SubmissionStatus::Approved, SubmissionStatus::Completed => 'success',
                default => 'neutral',
            },
            'version_count' => $submission->versions->count(),
            'latest_version' => $submission->versions->sortByDesc('version_number')->first(),
        ]);
    }

    /**
     * The participant's Gates of the active program's weeks, with their evaluation or readiness status and blocker count
     * in the participant's session that includes the week, and the link to review the Gate there (null without such a
     * session).
     *
     * @return Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: ?string}>
     */
    private function gateRows(User $participant): Collection
    {
        ['gateRows' => $gateRows] = $this->gateOverview->participantOverview($participant);

        return $gateRows->map(function (array $row) use ($participant): array {
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
                'review_url' => $row['trainingSession'] ? route('training.coach.gates.show', [$row['trainingSession'], $row['gate']->week, $participant, $row['gate']]) : null,
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
