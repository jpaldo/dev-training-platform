<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
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
     * Everything the coach participant page renders for the participant's work in one curriculum: the curriculum and
     * its dates, the participant's current mentor there (null when not assigned), whether the viewer is an admin
     * (otherwise they are that mentor), the submission and weekly video rows of the curriculum's weeks, the Gate rows
     * of its active weeks and the page tabs. `shareDomain` is the Google Workspace domain the weekly videos are shared
     * with (null when none is configured).
     *
     * @return array{participant: User, program: Program, programDates: string, mentorName: ?string, isAdmin: bool, submissions: Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, version_count: int, latest_version: ?SubmissionVersion}>, videoRecordings: Collection<int, WeeklyVideoRecording>, shareDomain: ?string, gates: Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: string}>, tabs: list<array{key: string, label: string}>, selectedTab: string}
     */
    public function showData(User $viewer, Program $program, User $participant): array
    {
        $inProgram = fn (Builder $weeks): Builder => $weeks->where('program_id', $program->id);
        $participant->load([
            'activitySubmissions' => fn (Relation $submissions): Relation => $submissions
                ->whereHas('activity.week', $inProgram)
                ->with(['activity.week', 'versions']),
            'weeklyVideoRecordings' => fn (Relation $recordings): Relation => $recordings
                ->whereHas('week', $inProgram)
                ->with('week'),
        ]);

        return [
            'participant' => $participant,
            'program' => $program,
            'programDates' => $program->dateRangeLabel(),
            'mentorName' => $this->mentorAssignments->currentMentor($program, $participant)?->name,
            'isAdmin' => $viewer->hasAnyRole(UserRole::Admin),
            'submissions' => $this->submissionRows($participant),
            'videoRecordings' => $participant->weeklyVideoRecordings->sortByDesc('chosen_at')->values(),
            'shareDomain' => $this->googleDrive->getShareDomain(),
            'gates' => $this->gateRows($program, $participant),
            'tabs' => [
                ['key' => 'submissions', 'label' => 'Submissions'],
                ['key' => 'gates', 'label' => 'Gates'],
            ],
            'selectedTab' => 'submissions',
        ];
    }

    /**
     * The participant's attempts in the curriculum with their status label and tone, version count and latest version.
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
     * The Gates of the curriculum's active weeks with the participant's evaluation or readiness status and blocker
     * count, and the link to review the Gate.
     *
     * @return Collection<int, array{gate: TrainingGate, status_label: string, tone: string, blocker_count: int, review_url: string}>
     */
    private function gateRows(Program $program, User $participant): Collection
    {
        return $this->gateOverview->participantProgramOverview($program, $participant)->map(function (array $row) use ($program, $participant): array {
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
                'review_url' => route('training.coach.gates.show', [$program, $row['gate']->week, $participant, $row['gate']]),
            ];
        });
    }
}
