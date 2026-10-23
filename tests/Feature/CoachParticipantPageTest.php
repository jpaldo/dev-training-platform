<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
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

    public function test_heading_and_session_card_come_first_then_three_tabs_with_submissions_selected(): void
    {
        $session = TrainingSession::factory()->create(['title' => 'L4 November', 'starts_on' => '2026-11-09', 'ends_on' => '2026-12-06']);
        [$coach, $participant] = $this->mentoredParticipant($session, 'Coach Carter');

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANT DETAIL',
                e($participant->name),
                'Training session',
                'L4 November',
                '9 Nov – 6 Dec 2026',
                'Mentor: Coach Carter',
                'Assigned to you — outcome-changing actions are available.',
                'role="tablist" aria-label="Participant sections"',
                'id="participant-tab-submissions"',
                'id="participant-tab-gates"',
                'id="participant-tab-attendance"',
            ], false)
            ->assertSee('id="participant-tab-submissions" aria-controls="participant-panel-submissions" aria-selected="true"', false)
            ->assertDontSee('participant-tab-week-four', false)
            ->assertDontSee('Assigned Mentors');
    }

    public function test_an_admin_has_full_access_and_sees_when_no_mentor_is_assigned(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->withParticipants($participant)->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($session)->create(['status' => 'failed']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.participants.show', [$session, $participant]))
            ->assertOk()
            ->assertSee('No mentor assigned.')
            ->assertSee('Admins have full access.')
            ->assertSee('This attempt is closed. Create a separate retry if appropriate.')
            ->assertSee('action="'.route('coach.reviews.retry', $version).'"', false);
    }

    public function test_the_current_mentor_sees_the_failed_attempt_with_its_retry_action(): void
    {
        $session = TrainingSession::factory()->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($session)->create(['status' => 'failed']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
            ->assertOk()
            ->assertSee('FAILED')
            ->assertSee('href="'.route('coach.reviews.show', $version).'"', false)
            ->assertSee('action="'.route('coach.reviews.retry', $version).'"', false)
            ->assertDontSee('Read only');
    }

    #[DataProvider('coachesWhoAreNotTheCurrentMentor')]
    public function test_a_coach_who_is_not_the_participants_current_mentor_in_the_session_is_forbidden(string $coachKind): void
    {
        $session = TrainingSession::factory()->create();
        [$currentMentor, $participant] = $this->mentoredParticipant($session);
        $coach = User::factory()->create(['role' => 'coach']);
        match ($coachKind) {
            'previous mentor' => MentorAssignment::factory()->ended()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]),
            'mentor in another session' => $this->mentoredParticipant(TrainingSession::factory()->create(), coach: $coach, participant: $participant),
            'session mentor of someone else' => $this->mentoredParticipant($session, coach: $coach),
            'unrelated coach' => null,
        };

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))->assertForbidden();
        $this->actingAs($currentMentor)->get(route('coach.participants.show', [$session, $participant]))->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function coachesWhoAreNotTheCurrentMentor(): array
    {
        return [
            'previous mentor' => ['previous mentor'],
            'mentor of the participant in another session' => ['mentor in another session'],
            'session mentor of another participant' => ['session mentor of someone else'],
            'coach with no assignment' => ['unrelated coach'],
        ];
    }

    public function test_a_participant_who_is_not_in_the_session_is_not_found(): void
    {
        $participant = User::factory()->create();
        TrainingSession::factory()->withParticipants($participant)->create();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.participants.show', [TrainingSession::factory()->create(), $participant]))
            ->assertNotFound();
    }

    public function test_participants_cannot_open_the_page(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('coach.participants.show', [$session, $participant]))->assertForbidden();
    }

    public function test_only_the_sessions_work_is_shown(): void
    {
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $session = TrainingSession::factory()->forWeek($week)->create();
        $otherSession = TrainingSession::factory()->forWeek($week)->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        $this->mentoredParticipant($otherSession, coach: $coach, participant: $participant);
        ActivitySubmission::factory()->for($participant, 'participant')->for($session)->for(Activity::factory()->for($week, 'week')->state(['title' => 'This Session Activity']))->create();
        ActivitySubmission::factory()->for($participant, 'participant')->for($otherSession)->for(Activity::factory()->for($week, 'week')->state(['title' => 'Other Session Activity']))->create();
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->for($session)->create(['name' => 'this-session.mp4']);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->for($otherSession)->create(['name' => 'other-session.mp4']);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
            ->assertOk()
            ->assertSee('Week 2 · This Session Activity')
            ->assertSee('this-session.mp4')
            ->assertDontSee('Other Session Activity')
            ->assertDontSee('other-session.mp4');
    }

    #[DataProvider('submissionStatuses')]
    public function test_submission_row_badge_shows_the_attempt_status_with_its_tone(string $status, string $badge): void
    {
        $activity = Activity::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Charter']);
        $session = TrainingSession::factory()->forWeek($activity->week)->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session)->create(['status' => $status]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
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

    public function test_gate_rows_list_the_sessions_weeks_with_the_evaluation_or_readiness_status_in_the_session(): void
    {
        $program = Program::factory()->create();
        $passedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Passed Gate']);
        $blockedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]), 'week')->create(['title' => 'Blocked Gate']);
        TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 5, 'position' => 3]), 'week')->create(['title' => 'Later Gate']);
        Activity::factory()->for($blockedGate->week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($passedGate->week)->create(['weeks_count' => 4]);
        $otherSession = TrainingSession::factory()->forWeek($passedGate->week)->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        GateEvaluation::factory()->for($session)->for($passedGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($otherSession)->for($blockedGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Passed Gate', 'Completed', 'href="'.route('training.coach.gates.show', [$session, $passedGate->week, $participant, $passedGate]).'"', 'Review Gate'], false)
            ->assertSeeInOrder(['Week 3 · Blocked Gate', 'Not ready', '(1 blocker(s))', 'href="'.route('training.coach.gates.show', [$session, $blockedGate->week, $participant, $blockedGate]).'"'], false)
            ->assertDontSee('Later Gate');
    }

    #[DataProvider('savedGateStatuses')]
    public function test_gate_row_badge_shows_the_saved_evaluation_status_with_its_tone(GateEvaluationStatus $status, string $badge): void
    {
        $gate = TrainingGate::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Evaluated Gate']);
        $session = TrainingSession::factory()->forWeek($gate->week)->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        GateEvaluation::factory()->for($session)->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
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
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $session = TrainingSession::factory()->forWeek($week)->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->for($session)->create([
            'drive_file_id' => 'drive-video-2',
            'name' => 'week-2-walkthrough.mp4',
            'size_bytes' => 1572864,
            'chosen_at' => '2026-10-22 09:30:00',
        ]);

        $html = $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
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
        $program = Program::factory()->create();
        $sharedWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $unsharedWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 3]);
        $session = TrainingSession::factory()->forWeek($sharedWeek)->create();
        [$coach, $participant] = $this->mentoredParticipant($session);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($sharedWeek, 'week')->for($session)->shared()->create(['chosen_at' => now()->subDay()]);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($unsharedWeek, 'week')->for($session)->create(['chosen_at' => now()]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))
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
     * Give a new participant, in a new session of a new program, the given number of the program's weeks, each with a
     * Gate, a submission with two versions, a weekly video recording and a completed attendance session, plus an open
     * attendance session, and count the queries of the page request of the participant's mentor in that session, after
     * a warm-up request.
     */
    private function participantPageQueryCount(int $rows): int
    {
        $program = Program::factory()->create();
        $session = TrainingSession::factory()->for($program)->create(['weeks_count' => 4]);
        [$coach, $participant] = $this->mentoredParticipant($session);

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position, 'position' => $position]);
            TrainingGate::factory()->for($week, 'week')->create();
            $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session)->create(['status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'submitted']);
            WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->for($session)->create(['name' => "week-{$position}.mp4"]);
            AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subDays($position)->subHours(8), 'check_out_at' => now()->subDays($position), 'total_seconds' => 28800]);
        }
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHour()]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.participants.show', [$session, $participant]))->assertOk()->assertSee("week-{$rows}.mp4")->assertSee('Review Gate');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Add the participant (a new one unless given) to the session and make the coach (a new one, named as given, unless
     * given) a mentor of the session and the participant's current mentor there.
     *
     * @return array{User, User}
     */
    private function mentoredParticipant(TrainingSession $session, ?string $coachName = null, ?User $coach = null, ?User $participant = null): array
    {
        $coach ??= User::factory()->create(['role' => 'coach', ...($coachName ? ['name' => $coachName] : [])]);
        $participant ??= User::factory()->create();
        $session->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        $session->mentors()->syncWithoutDetaching([$coach->id]);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        return [$coach, $participant];
    }
}
