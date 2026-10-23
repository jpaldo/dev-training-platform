<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\MentorAssignment;
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
     * Everything the coach participant page renders: the participant's current mentors (their names, each once), the
     * viewer's access level, the submission and weekly video rows, the Gate rows, the attendance summary and the page
     * tabs. The viewer manages the participant when they are an admin or the participant's current mentor in at least
     * one session; each submission row says whether the viewer manages it (its session), and only the weekly videos of
     * those sessions are listed. `shareDomain` is the Google Workspace domain the weekly videos are shared with (null
     * when none is configured).
     *
     * @return array{participant: User, mentorNames: string, access: string, canManage: bool, submissions: Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion, can_manage: bool}>, videoRecordings: Collection<int, WeeklyVideoRecording>, shareDomain: ?string, gates: Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: ?string}>, attendance: array{completed_sessions: int, total_hours: float, active_session: ?AttendanceSession}, tabs: list<array{key: string, label: string}>, selectedTab: string}
     */
    public function showData(User $viewer, User $participant): array
    {
        $participant->load(['activitySubmissions.activity.week', 'activitySubmissions.versions', 'weeklyVideoRecordings.week', 'attendanceSessions', 'currentMentors:id,name']);
        $isAdmin = $viewer->hasAnyRole(UserRole::Admin);
        $managedSessionIds = $isAdmin ? [] : $this->mentoredSessionIds($viewer, $participant);
        $canManage = $isAdmin || $managedSessionIds !== [];
        $managesSession = fn (int $trainingSessionId): bool => $isAdmin || in_array($trainingSessionId, $managedSessionIds, true);

        return [
            'participant' => $participant,
            'mentorNames' => $participant->currentMentors->pluck('name')->unique()->join(', '),
            'access' => match (true) {
                $isAdmin => 'admin',
                $canManage => 'manage',
                default => 'read_only',
            },
            'canManage' => $canManage,
            'submissions' => $this->submissionRows($participant, $managesSession),
            'videoRecordings' => $participant->weeklyVideoRecordings
                ->filter(fn (WeeklyVideoRecording $recording): bool => $managesSession($recording->training_session_id))
                ->sortByDesc('chosen_at')
                ->values(),
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
     * The sessions in which the viewer (a coach) is the participant's current mentor.
     *
     * @return list<int>
     */
    private function mentoredSessionIds(User $viewer, User $participant): array
    {
        return MentorAssignment::query()
            ->current()
            ->where('participant_id', $participant->id)
            ->where('mentor_id', $viewer->id)
            ->pluck('training_session_id')
            ->all();
    }

    /**
     * The participant's attempts with their status label and tone, version count, latest version and whether the
     * viewer manages the attempt's session.
     *
     * @param  callable(int): bool  $managesSession
     * @return Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion, can_manage: bool}>
     */
    private function submissionRows(User $participant, callable $managesSession): Collection
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
            'can_manage' => $managesSession($submission->training_session_id),
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
