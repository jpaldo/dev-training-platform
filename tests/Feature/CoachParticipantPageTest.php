<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachParticipantPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_heading_and_curriculum_card_come_first_then_two_tabs_with_submissions_selected(): void
    {
        $program = Program::factory()->withWeeks(4, Carbon::parse('2026-11-09'))->create(['name' => 'L4 November']);
        [$coach, $participant] = $this->mentoredParticipant($program, 'Coach Carter');

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))
            ->assertOk()
            ->assertSeeInOrder([
                'PARTICIPANT DETAIL',
                e($participant->name),
                'id="participant-program-title"',
                'Curriculum',
                'L4 November',
                '9 Nov – 6 Dec 2026',
                'Mentor: Coach Carter',
                'Assigned to you — outcome-changing actions are available.',
                'role="tablist" aria-label="Participant sections"',
                'id="participant-tab-submissions"',
                'id="participant-tab-gates"',
            ], false)
            ->assertSee('id="participant-tab-submissions" aria-controls="participant-panel-submissions" aria-selected="true"', false)
            ->assertDontSee('participant-tab-attendance', false)
            ->assertDontSee('participant-panel-attendance', false)
            ->assertDontSee('participant-tab-week-four', false)
            ->assertDontSee('Assigned Mentors');
    }

    public function test_an_admin_has_full_access_and_sees_when_no_mentor_is_assigned(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withParticipants($participant)->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($this->activityIn($program))->create(['status' => 'failed']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.participants.show', [$program, $participant]))
            ->assertOk()
            ->assertSee('No mentor assigned.')
            ->assertSee('Admins have full access.')
            ->assertSee('This attempt is closed. Create a separate retry if appropriate.')
            ->assertSee('action="'.route('coach.reviews.retry', $version).'"', false);
    }

    public function test_the_current_mentor_sees_the_failed_attempt_with_its_retry_action(): void
    {
        $program = Program::factory()->create();
        [$coach, $participant] = $this->mentoredParticipant($program);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($this->activityIn($program))->create(['status' => 'failed']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))
            ->assertOk()
            ->assertSee('FAILED')
            ->assertSee('href="'.route('coach.reviews.show', $version).'"', false)
            ->assertSee('action="'.route('coach.reviews.retry', $version).'"', false)
            ->assertDontSee('Read only');
    }

    #[DataProvider('coachesWhoAreNotTheCurrentMentor')]
    public function test_a_coach_who_is_not_the_participants_current_mentor_in_the_curriculum_is_forbidden(string $coachKind): void
    {
        $program = Program::factory()->create();
        [$currentMentor, $participant] = $this->mentoredParticipant($program);
        $coach = User::factory()->create(['role' => 'coach']);
        match ($coachKind) {
            'previous mentor' => MentorAssignment::factory()->ended()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]),
            'mentor in another curriculum' => $this->mentoredParticipant(Program::factory()->create(), coach: $coach, participant: $participant),
            'curriculum mentor of someone else' => $this->mentoredParticipant($program, coach: $coach),
            'unrelated coach' => null,
        };

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))->assertForbidden();
        $this->actingAs($currentMentor)->get(route('coach.participants.show', [$program, $participant]))->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function coachesWhoAreNotTheCurrentMentor(): array
    {
        return [
            'previous mentor' => ['previous mentor'],
            'mentor of the participant in another curriculum' => ['mentor in another curriculum'],
            'curriculum mentor of another participant' => ['curriculum mentor of someone else'],
            'coach with no assignment' => ['unrelated coach'],
        ];
    }

    public function test_a_participant_who_is_not_in_the_curriculum_is_not_found(): void
    {
        $participant = User::factory()->create();
        Program::factory()->withParticipants($participant)->create();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.participants.show', [Program::factory()->create(), $participant]))
            ->assertNotFound();
    }

    public function test_participants_cannot_open_the_page(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('coach.participants.show', [$program, $participant]))->assertForbidden();
    }

    public function test_only_the_curriculums_work_is_shown(): void
    {
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $otherWeek = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        [$coach, $participant] = $this->mentoredParticipant($program);
        $this->mentoredParticipant($otherWeek->program, coach: $coach, participant: $participant);
        ActivitySubmission::factory()->for($participant, 'participant')->for(Activity::factory()->for($week, 'week')->state(['title' => 'This Curriculum Activity']))->create();
        ActivitySubmission::factory()->for($participant, 'participant')->for(Activity::factory()->for($otherWeek, 'week')->state(['title' => 'Other Curriculum Activity']))->create();
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create(['name' => 'this-curriculum.mp4']);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($otherWeek, 'week')->create(['name' => 'other-curriculum.mp4']);

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))
            ->assertOk()
            ->assertSee('Week 2 · This Curriculum Activity')
            ->assertSee('this-curriculum.mp4')
            ->assertDontSee('Other Curriculum Activity')
            ->assertDontSee('other-curriculum.mp4');
    }

    #[DataProvider('submissionStatuses')]
    public function test_submission_row_badge_shows_the_attempt_status_with_its_tone(string $status, string $badge): void
    {
        $activity = Activity::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Charter']);
        [$coach, $participant] = $this->mentoredParticipant($activity->week->program);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$activity->week->program, $participant]))
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

    public function test_gate_rows_list_the_curriculums_active_weeks_with_the_evaluation_or_readiness_status(): void
    {
        $program = Program::factory()->create();
        $passedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Passed Gate']);
        $blockedGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]), 'week')->create(['title' => 'Blocked Gate']);
        TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 5, 'position' => 3, 'is_active' => false]), 'week')->create(['title' => 'Inactive Week Gate']);
        TrainingGate::factory()->for(TrainingWeek::factory()->create(['week_number' => 4, 'position' => 1]), 'week')->create(['title' => 'Other Curriculum Gate']);
        Activity::factory()->for($blockedGate->week, 'week')->create();
        [$coach, $participant] = $this->mentoredParticipant($program);
        GateEvaluation::factory()->for($passedGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))
            ->assertOk()
            ->assertSeeInOrder(['Week 2 · Passed Gate', 'Completed', 'href="'.route('training.coach.gates.show', [$program, $passedGate->week, $participant, $passedGate]).'"', 'Review Gate'], false)
            ->assertSeeInOrder(['Week 3 · Blocked Gate', 'Not ready', '(1 blocker(s))', 'href="'.route('training.coach.gates.show', [$program, $blockedGate->week, $participant, $blockedGate]).'"'], false)
            ->assertDontSee('Inactive Week Gate')
            ->assertDontSee('Other Curriculum Gate');
    }

    #[DataProvider('savedGateStatuses')]
    public function test_gate_row_badge_shows_the_saved_evaluation_status_with_its_tone(GateEvaluationStatus $status, string $badge): void
    {
        $gate = TrainingGate::factory()->for(TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]), 'week')->create(['title' => 'Evaluated Gate']);
        [$coach, $participant] = $this->mentoredParticipant($gate->week->program);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$gate->week->program, $participant]))
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
        [$coach, $participant] = $this->mentoredParticipant($week->program);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create([
            'drive_file_id' => 'drive-video-2',
            'name' => 'week-2-walkthrough.mp4',
            'size_bytes' => 1572864,
            'chosen_at' => '2026-10-22 09:30:00',
        ]);

        $html = $this->actingAs($coach)->get(route('coach.participants.show', [$week->program, $participant]))
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
        [$coach, $participant] = $this->mentoredParticipant($program);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($sharedWeek, 'week')->shared()->create(['chosen_at' => now()->subDay()]);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($unsharedWeek, 'week')->create(['chosen_at' => now()]);

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Week 3</strong>',
                'Not shared yet. Ask the participant to share it in Google Drive.',
                '<strong>Week 2</strong>',
                '>Shared with fullscale.io</p>',
            ], false);
    }

    public function test_participant_page_query_count_does_not_grow_with_submissions_and_videos(): void
    {
        $this->assertSame($this->participantPageQueryCount(1), $this->participantPageQueryCount(3));
    }

    /**
     * Give a new participant, in a new curriculum, the given number of the curriculum's weeks, each with a
     * Gate, a submission with two versions and a weekly video recording, and count the queries of the page request of
     * the participant's mentor in that curriculum, after a warm-up request.
     */
    private function participantPageQueryCount(int $rows): int
    {
        $program = Program::factory()->create();
        [$coach, $participant] = $this->mentoredParticipant($program);

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position, 'position' => $position]);
            TrainingGate::factory()->for($week, 'week')->create();
            $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'submitted']);
            WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create(['name' => "week-{$position}.mp4"]);
        }

        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.participants.show', [$program, $participant]))->assertOk()->assertSee("week-{$rows}.mp4")->assertSee('Review Gate');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Add the participant (a new one unless given) to the curriculum and make the coach (a new one, named as given,
     * unless given) a mentor of the curriculum and the participant's current mentor there.
     *
     * @return array{User, User}
     */
    private function mentoredParticipant(Program $program, ?string $coachName = null, ?User $coach = null, ?User $participant = null): array
    {
        $coach ??= User::factory()->create(['role' => 'coach', ...($coachName ? ['name' => $coachName] : [])]);
        $participant ??= User::factory()->create();
        $program->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        $program->mentors()->syncWithoutDetaching([$coach->id]);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        return [$coach, $participant];
    }

    /**
     * A new activity in a new week of the curriculum.
     */
    private function activityIn(Program $program): Activity
    {
        return Activity::factory()->for(TrainingWeek::factory()->for($program), 'week')->create();
    }
}
