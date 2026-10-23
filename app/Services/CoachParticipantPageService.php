<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class CoachParticipantPageService
{
    public function __construct(
        private readonly CoachGateOverviewService $gateOverview,
        private readonly GoogleDriveConfigurationService $googleDrive,
        private readonly MentorAssignmentService $mentorAssignments,
    ) {}

    /**
     * Everything the coach participant page renders for the participant's work in one training session: the session,
     * the participant's current mentor there (null when not assigned), whether the viewer is an admin (otherwise they
     * are that mentor), the session's submission and weekly video rows, the Gate rows of the session's weeks, the
     * attendance summary (attendance is not per session) and the page tabs. `shareDomain` is the Google Workspace domain
     * the weekly videos are shared with (null when none is configured).
     *
     * @return array{participant: User, trainingSession: TrainingSession, mentorName: ?string, isAdmin: bool, submissions: Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion}>, videoRecordings: Collection<int, WeeklyVideoRecording>, shareDomain: ?string, gates: Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: string}>, attendance: array{completed_sessions: int, total_hours: float, active_session: ?AttendanceSession}, tabs: list<array{key: string, label: string}>, selectedTab: string}
     */
    public function showData(User $viewer, TrainingSession $trainingSession, User $participant): array
    {
        $participant->load([
            'activitySubmissions' => fn (Relation $submissions): Relation => $submissions
                ->where('training_session_id', $trainingSession->id)
                ->with(['activity.week', 'versions']),
            'weeklyVideoRecordings' => fn (Relation $recordings): Relation => $recordings
                ->where('training_session_id', $trainingSession->id)
                ->with('week'),
            'attendanceSessions',
        ]);

        return [
            'participant' => $participant,
            'trainingSession' => $trainingSession,
            'mentorName' => $this->mentorAssignments->currentMentor($trainingSession, $participant)?->name,
            'isAdmin' => $viewer->hasAnyRole(UserRole::Admin),
            'submissions' => $this->submissionRows($participant),
            'videoRecordings' => $participant->weeklyVideoRecordings->sortByDesc('chosen_at')->values(),
            'shareDomain' => $this->googleDrive->getShareDomain(),
            'gates' => $this->gateRows($trainingSession, $participant),
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
     * The participant's attempts in the session with their status label and tone, version count and latest version.
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
     * The Gates of the session's weeks with the participant's evaluation or readiness status and blocker count in the
     * session, and the link to review the Gate there.
     *
     * @return Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: string}>
     */
    private function gateRows(TrainingSession $trainingSession, User $participant): Collection
    {
        return $this->gateOverview->participantSessionOverview($trainingSession, $participant)->map(function (array $row) use ($trainingSession, $participant): array {
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
                'blocker_count' => count($row['readiness']),
                'review_url' => route('training.coach.gates.show', [$trainingSession, $row['gate']->week, $participant, $row['gate']]),
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
