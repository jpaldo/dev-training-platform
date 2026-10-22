<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourPeerInterviewWaiver;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachParticipantPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_heading_comes_first_then_four_tabs_with_submissions_selected(): void
    {
        $this->peerInterviewActivity();
        [$coach, $participant] = $this->assignedCoachAndParticipant();

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANT DETAIL',
                e($participant->name),
                'Assigned Coaches',
                'role="tablist" aria-label="Participant sections"',
                'id="participant-tab-submissions"',
                'id="participant-tab-gates"',
                'id="participant-tab-logs-attendance"',
                'id="participant-tab-week-four"',
            ], false)
            ->assertSee('id="participant-tab-submissions" aria-controls="participant-panel-submissions" aria-selected="true"', false)
            ->assertSee('id="participant-panel-week-four"', false)
            ->assertSee('Peer mock interview prerequisite is active.');
    }

    public function test_heading_shows_the_shared_training_path_label(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $participant->update(['training_path' => 'automation_manual']);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSee(e($participant->email).' · Automation / Manual', false);
    }

    public function test_week_four_tab_is_hidden_without_the_peer_interview_activity(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'id="participant-tab-submissions"',
                'id="participant-tab-gates"',
                'id="participant-tab-logs-attendance"',
            ], false)
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

    public function test_gate_rows_show_the_evaluation_or_readiness_status_and_daily_logs(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $program = Program::factory()->create();
        $passedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Passed Gate']);
        TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]), 'week')->create(['title' => 'Blocked Gate']);
        GateEvaluation::factory()->for($passedGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        DailyLog::factory()->for($participant, 'participant')->for(Activity::factory()->for($passedGate->week, 'week'))->create();

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Passed Gate', 'Passed', 'Daily logs: 1 / 5'])
            ->assertSeeInOrder(['Week 3 · Blocked Gate', 'Not ready', '(2 blocker(s))', 'Daily logs: 0 / 5']);
    }

    public function test_gate_row_daily_logs_use_the_configured_weekly_minimum(): void
    {
        config(['training.daily_logs_per_week' => 3]);
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $gate = TrainingGate::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Logged Gate']);
        DailyLog::factory()->for($participant, 'participant')->for(Activity::factory()->for($gate->week, 'week'))->create();

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Logged Gate', 'Daily logs: 1 / 3']);
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
            'needs revision' => [GateEvaluationStatus::NeedsRevision, 'border-amber-500 text-amber-200">Needs revision</span>'],
            'failed' => [GateEvaluationStatus::Failed, 'border-danger text-rose-200">Failed</span>'],
            'passed' => [GateEvaluationStatus::Passed, 'border-emerald-400 text-emerald-200">Passed</span>'],
        ];
    }

    public function test_participant_page_query_count_does_not_grow_with_submissions_videos_and_attendance(): void
    {
        $peerActivity = $this->peerInterviewActivity();

        $this->assertSame($this->participantPageQueryCount(1, $peerActivity), $this->participantPageQueryCount(3, $peerActivity));
    }

    /**
     * Give a new participant the given number of weeks, each with a Gate, a submission with two versions, a daily log,
     * a weekly video recording and a completed attendance session, plus an open session and a peer interview waiver,
     * and count the queries of the assigned coach's page request after a warm-up request.
     */
    private function participantPageQueryCount(int $rows, Activity $peerActivity): int
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        $program = Program::factory()->create();

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position + 1, 'position' => $position]);
            TrainingGate::factory()->for($week, 'week')->create();
            $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'submitted']);
            DailyLog::factory()->for($participant, 'participant')->for($activity)->create(['logged_on' => now()->subDays($position)->toDateString()]);
            WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'path' => "videos/{$position}.mp4", 'original_filename' => "week-{$position}.mp4", 'mime_type' => 'video/mp4', 'size_bytes' => 1048576, 'uploaded_at' => now()]);
            AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subDays($position)->subHours(8), 'check_out_at' => now()->subDays($position), 'total_seconds' => 28800]);
        }
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHour()]);
        WeekFourPeerInterviewWaiver::create(['participant_id' => $participant->id, 'activity_id' => $peerActivity->id, 'authorized_by_id' => $coach->id, 'waived_at' => now()]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.participants.show', $participant))->assertOk()->assertSee("week-{$rows}.mp4")->assertSee('Peer Mock Interview: Skipped');
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

    /**
     * The Week 4 peer mock interview activity (Activity 2 of a Week 4).
     */
    private function peerInterviewActivity(): Activity
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 4, 'position' => 4]);

        return Activity::factory()->for($week, 'week')->create(['position' => 2]);
    }
}
