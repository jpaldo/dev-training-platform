<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachParticipantPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_heading_comes_first_then_three_tabs_with_submissions_selected(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 4, 'position' => 4]);
        Activity::factory()->for($week, 'week')->create(['position' => 2]);
        [$coach, $participant] = $this->assignedCoachAndParticipant();

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANT DETAIL',
                e($participant->name),
                'Assigned Mentors',
                'role="tablist" aria-label="Participant sections"',
                'id="participant-tab-submissions"',
                'id="participant-tab-gates"',
                'id="participant-tab-attendance"',
            ], false)
            ->assertSee('id="participant-tab-submissions" aria-controls="participant-panel-submissions" aria-selected="true"', false)
            ->assertDontSee('participant-tab-week-four', false)
            ->assertDontSee('participant-panel-week-four', false)
            ->assertDontSee('Peer Mock Interview Requirement');
    }

    #[DataProvider('viewerAccess')]
    public function test_access_notice_and_retry_action_follow_the_viewer(string $role, bool $isAssigned, string $notice, bool $canRetry): void
    {
        $participant = User::factory()->create();
        $viewer = User::factory()->create(['role' => $role]);
        if ($isAssigned) {
            $viewer->assignedParticipants()->attach($participant->id);
        }
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'failed']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $retryForm = 'action="'.route('coach.reviews.retry', $version).'"';

        $response = $this->actingAs($viewer)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSee($notice)
            ->assertSee('FAILED')
            ->assertSee('href="'.route('coach.reviews.show', $version).'"', false);

        if ($canRetry) {
            $response->assertSee($retryForm, false)->assertSee('This attempt is closed. Create a separate retry if appropriate.');
        } else {
            $response->assertDontSee($retryForm, false)->assertSee('Read only — this participant is not assigned to you.');
        }
    }

    /**
     * @return array<string, array{string, bool, string, bool}>
     */
    public static function viewerAccess(): array
    {
        return [
            'admin' => ['admin', false, 'Admins have full access.', true],
            'assigned coach' => ['coach', true, 'Assigned to you — outcome-changing actions are available.', true],
            'unassigned coach' => ['coach', false, 'Not assigned to you — read only.', false],
        ];
    }

    #[DataProvider('submissionStatuses')]
    public function test_submission_row_badge_shows_the_attempt_status_with_its_tone(string $status, string $badge): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $activity = Activity::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Charter']);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Charter', $badge], false);
    }

    /**
     * Each attempt status and its badge on the coach participant page.
     *
     * @return array<string, array{string, string}>
     */
    public static function submissionStatuses(): array
    {
        return [
            'draft' => ['draft', 'border-accent text-cyan-200">DRAFT</span>'],
            'submitted' => ['submitted', 'border-line text-muted">SUBMITTED</span>'],
            'under review' => ['under_review', 'border-line text-muted">UNDER REVIEW</span>'],
            'needs revision' => ['needs_revision', 'border-accent text-cyan-200">NEEDS REVISION</span>'],
            'approved' => ['approved', 'border-emerald-400 text-emerald-200">APPROVED</span>'],
            'completed' => ['completed', 'border-emerald-400 text-emerald-200">COMPLETED</span>'],
            'failed' => ['failed', 'border-danger text-rose-200">FAILED</span>'],
        ];
    }

    public function test_gate_rows_show_the_evaluation_or_readiness_status(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $program = Program::factory()->create();
        $passedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Passed Gate']);
        $blockedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]), 'week')->create(['title' => 'Blocked Gate']);
        Activity::factory()->for($blockedGate->week, 'week')->create();
        GateEvaluation::factory()->for($passedGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Passed Gate', 'Completed'])
            ->assertSeeInOrder(['Week 3 · Blocked Gate', 'Not ready', '(1 blocker(s))'])
            ->assertDontSee('Daily logs');
    }

    #[DataProvider('savedGateStatuses')]
    public function test_gate_row_badge_shows_the_saved_evaluation_status_with_its_tone(GateEvaluationStatus $status, string $badge): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $gate = TrainingGate::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Evaluated Gate']);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Evaluated Gate', $badge], false);
    }

    /**
     * Each status a Gate evaluation can be saved with and its badge on the coach participant page.
     *
     * @return array<string, array{GateEvaluationStatus, string}>
     */
    public static function savedGateStatuses(): array
    {
        return [
            'not ready' => [GateEvaluationStatus::NotReady, 'border-line text-muted">Not ready</span>'],
            'needs revision' => [GateEvaluationStatus::NeedsRevision, 'border-amber-500 text-amber-200">Incomplete</span>'],
            'failed' => [GateEvaluationStatus::Failed, 'border-danger text-rose-200">Failed</span>'],
            'passed' => [GateEvaluationStatus::Passed, 'border-emerald-400 text-emerald-200">Completed</span>'],
        ];
    }

    public function test_the_weekly_video_card_shows_the_chosen_video_with_a_lazy_preview_and_the_mentor_help_line(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create([
            'drive_file_id' => 'drive-video-2',
            'name' => 'week-2-walkthrough.mp4',
            'size_bytes' => 1572864,
            'chosen_at' => '2026-10-22 09:30:00',
        ]);

        $html = $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'Weekly Video Recording',
                '<strong>Week 2</strong>',
                '>week-2-walkthrough.mp4</p>',
                '>1.5 MB · chosen Oct 22, 2026 9:30 AM</p>',
                'href="https://drive.google.com/file/d/drive-video-2/view" target="_blank" rel="noopener noreferrer"',
                '>Open in Drive<',
                'data-resource-preview',
                'Show preview',
                'data-preview-src="https://drive.google.com/file/d/drive-video-2/preview"',
                'unshared in the participant\'s Google Drive. Ask them to choose it again.',
            ], false)
            ->assertDontSee('unshared in your Google Drive', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/', $html);
    }

    public function test_the_weekly_video_card_shows_each_videos_sharing_state_with_the_configured_domain(): void
    {
        config()->set('services.google_drive.share_domain', 'fullscale.io');
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $program = Program::factory()->create();
        $sharedWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $unsharedWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 3]);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($sharedWeek, 'week')->shared()->create(['chosen_at' => now()->subDay()]);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($unsharedWeek, 'week')->create(['chosen_at' => now()]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Week 3</strong>',
                'Not shared yet. Ask the participant to share it in Google Drive.',
                '<strong>Week 2</strong>',
                '>Shared with fullscale.io</p>',
            ], false);
    }

    public function test_participant_page_query_count_does_not_grow_with_submissions_videos_and_attendance(): void
    {
        $this->assertSame($this->participantPageQueryCount(1), $this->participantPageQueryCount(3));
    }

    /**
     * Give a new participant the given number of weeks of a new active program (earlier programs are deactivated),
     * each with a Gate, a submission with two versions, a weekly video recording and a completed attendance session,
     * plus an open session, and count the queries of the assigned coach's page request after a warm-up request.
     */
    private function participantPageQueryCount(int $rows): int
    {
        Program::query()->update(['is_active' => false]);
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $program = Program::factory()->create();

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position + 1, 'position' => $position]);
            TrainingGate::factory()->for($week, 'week')->create();
            $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'submitted']);
            WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create(['name' => "week-{$position}.mp4"]);
            AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subDays($position)->subHours(8), 'check_out_at' => now()->subDays($position), 'total_seconds' => 28800]);
        }
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHour()]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.participants.show', $participant))->assertOk()->assertSee("week-{$rows}.mp4");
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A participant and a coach assigned to them.
     *
     * @return array{User, User}
     */
    private function assignedCoachAndParticipant(): array
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        return [$coach, $participant];
    }
}
