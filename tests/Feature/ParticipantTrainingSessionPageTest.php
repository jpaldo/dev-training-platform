<?php

namespace Tests\Feature;

use App\Enums\ActivityScoreStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantTrainingSessionPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DRIVE_URL = 'https://drive.google.com/file/d/1aB_c-D2/view?usp=sharing';

    private const YOUTUBE_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    public function test_the_header_shows_the_session_title_program_dates_and_status(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $session = TrainingSession::factory()->for($program)->withParticipants($participant)->create([
            'title' => 'L4 Accelerator Training — November 2026',
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-12-06',
        ]);

        $this->travelTo('2026-11-10');

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'TRAINING SESSION',
                'L4 Accelerator Training — November 2026',
                'L4 Accelerator Training',
                '9 Nov – 6 Dec 2026',
                '>Active</span>',
            ], false);
    }

    public function test_there_is_one_tab_per_session_week_then_the_overview_and_the_current_week_is_selected(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        [$weekOne, $weekTwo] = collect([1, 2, 3])
            ->map(fn (int $number): TrainingWeek => TrainingWeek::factory()->for($program)->create(['week_number' => $number, 'position' => $number, 'title' => "Week title {$number}"]))
            ->all();
        $session = TrainingSession::factory()->for($program)->withParticipants($participant)->create(['weeks_count' => 2]);
        GateEvaluation::factory()->for(TrainingGate::factory()->for($weekOne, 'week'), 'gate')->for($session)->for($participant, 'participant')->create(['status' => GateEvaluationStatus::Passed]);
        TrainingGate::factory()->for($weekTwo, 'week')->create();

        $html = $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['id="session-tab-week-1"', 'id="session-tab-week-2"', 'id="session-tab-overview"'], false)
            ->assertSeeInOrder(['Week title 1', 'Week title 2', 'How this training works'])
            ->assertDontSee('id="session-tab-week-3"', false)
            ->assertDontSee('Week title 3')
            ->getContent();

        $this->assertMatchesRegularExpression('/id="session-tab-week-1"[^>]*aria-selected="false"/', $html);
        $this->assertMatchesRegularExpression('/id="session-tab-week-2"[^>]*aria-selected="true"/', $html);
        $this->assertMatchesRegularExpression('/id="session-panel-week-1"[^>]*\shidden\b/', $html);
        $this->assertMatchesRegularExpression('/id="session-panel-week-2"(?:(?!hidden)[^>])*>/', $html);
        $this->assertMatchesRegularExpression('/id="session-panel-overview"[^>]*\shidden\b/', $html);
        $this->assertSame(1, substr_count($html, 'data-page-tabs'));
    }

    public function test_a_session_without_weeks_shows_only_the_overview_and_an_empty_resources_tab(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->withParticipants($participant)->create();

        $html = $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSee('How this training works')
            ->assertSee('Resources (0)')
            ->assertSee('No resources in this session yet.')
            ->assertDontSee('id="session-tab-week-', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/id="session-tab-overview"[^>]*aria-selected="true"/', $html);
    }

    public function test_the_resources_tab_comes_last_counts_and_groups_only_this_sessions_resources(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create(['weeks_count' => 2]);
        TrainingSessionResource::factory()->for($session)->forWeek(2)->create(['title' => 'Kick-off recording', 'url' => self::YOUTUBE_URL]);
        TrainingSessionResource::factory()->for($session)->googleDrive()->create(['title' => 'Training handbook']);
        TrainingSessionResource::factory()->for(TrainingSession::factory()->forWeek($week)->withParticipants($participant))->create(['title' => 'Other session resource']);

        $html = $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['id="session-tab-week-1"', 'id="session-tab-overview"', 'id="session-tab-resources"', 'Resources (2)'], false)
            ->assertSeeInOrder(['Whole session', 'Training handbook', 'Week 2', 'Kick-off recording'])
            ->assertDontSee('Other session resource')
            ->assertDontSee('No resources in this session yet.')
            ->getContent();

        $this->assertMatchesRegularExpression('/id="session-tab-week-1"[^>]*aria-selected="true"/', $html);
        $this->assertMatchesRegularExpression('/id="session-panel-resources"[^>]*\shidden\b/', $html);
    }

    public function test_a_drive_resource_opens_in_drive_and_has_a_collapsed_lazy_preview_without_actions(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->withParticipants($participant)->create();
        TrainingSessionResource::factory()->for($session)->create(['title' => 'Training handbook', 'url' => self::DRIVE_URL]);

        $response = $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['Whole session', 'Training handbook', '>Google Drive<', 'drive.google.com', '>Open in Drive<', 'Show preview'], false)
            ->assertSee('href="'.e(self::DRIVE_URL).'" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('data-preview-src="https://drive.google.com/file/d/1aB_c-D2/preview"', false)
            ->assertSee('title="Preview of Training handbook"', false)
            ->assertDontSee('Rename')
            ->assertDontSee('Remove');

        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-resource-preview[^>]*\sopen/', $response->getContent());
    }

    public function test_a_plain_link_resource_opens_in_a_new_tab_without_a_preview(): void
    {
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->withParticipants($participant)->create(['weeks_count' => 2]);
        TrainingSessionResource::factory()->for($session)->forWeek(2)->create(['title' => 'Kick-off recording', 'url' => self::YOUTUBE_URL]);

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['Week 2', 'Kick-off recording', '>Link<', 'www.youtube.com', '>Open<'], false)
            ->assertSee('href="'.e(self::YOUTUBE_URL).'" target="_blank" rel="noopener noreferrer"', false)
            ->assertDontSee('Open in Drive')
            ->assertDontSee('Show preview')
            ->assertDontSee('<iframe', false);
    }

    public function test_session_page_query_count_does_not_grow_with_weeks_activities_gates_and_resources(): void
    {
        $this->assertSame($this->sessionPageQueryCount(1), $this->sessionPageQueryCount(3));
    }

    public function test_the_page_shows_the_published_score_of_an_activity_but_not_a_draft_score(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();

        foreach ([[1, ActivityScoreStatus::Published, 88], [2, ActivityScoreStatus::Draft, 41]] as [$position, $status, $totalScore]) {
            $activity = Activity::factory()->for($week, 'week')->withScoringDimensions()->create(['position' => $position]);
            $submission = ActivitySubmission::factory()->for($session)->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
            $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
            ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => $coach->id, 'status' => $status, 'total_score' => $totalScore, 'performance_band' => 'Proficient']);
        }

        $html = $this->actingAs($participant)->get(route('training.sessions.show', $session))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Score: <strong'));
        $this->assertStringContainsString('>88 / 100</strong>', $html);
        $this->assertStringNotContainsString('41 / 100', $html);
    }

    #[DataProvider('latestAttemptStatuses')]
    public function test_activity_row_shows_the_label_tone_and_action_of_the_latest_attempt_status(?string $status, string $label, string $badgeClasses, string $action, bool $isClosed): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Exploratory charter', 'position' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        if ($status !== null) {
            $submission = ActivitySubmission::factory()->for($session)->for($activity)->for($participant, 'participant')->create(['status' => $status]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status === 'draft' ? 'draft' : 'submitted']);
        }

        $response = $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Exploratory charter</strong>',
                '<span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-bold '.$badgeClasses.'">'.$label.'</span>',
                '>'.$action.'</',
            ], false);

        if ($isClosed) {
            $response->assertSee('Attempt 1 is closed. Waiting for mentor to create a retry.');
        } else {
            $response->assertDontSee('is closed.');
        }
    }

    /**
     * The latest attempt status (none before the first attempt), its badge label and tone classes, the action button
     * text and whether the closed-attempt note shows.
     *
     * @return array<string, array{?string, string, string, string, bool}>
     */
    public static function latestAttemptStatuses(): array
    {
        return [
            'not started' => [null, 'Not Started', 'border-line text-muted', 'Start', false],
            'draft' => ['draft', 'Draft', 'border-brand text-violet-200', 'Continue', false],
            'submitted' => ['submitted', 'Submitted', 'border-accent text-cyan-200', 'View', false],
            'resubmitted' => ['resubmitted', 'Resubmitted', 'border-accent text-cyan-200', 'View', false],
            'under review' => ['under_review', 'Under review', 'border-accent text-cyan-200', 'View', false],
            'needs revision' => ['needs_revision', 'Needs revision', 'border-amber-500 text-amber-200', 'Revise', false],
            'approved' => ['approved', 'Approved', 'border-emerald-400 text-emerald-200', 'View', false],
            'completed' => ['completed', 'Completed', 'border-emerald-400 text-emerald-200', 'View', false],
            'failed' => ['failed', 'Failed', 'border-danger text-rose-200', 'View attempts', true],
        ];
    }

    public function test_week_and_activity_links_open_the_week_in_the_session(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Set up the repository']);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('training.weeks.show', [$session, $week]).'"',
                'Open week →',
                '<strong>Set up the repository</strong>',
                'href="'.route('training.activities.show', [$session, $week, $activity]).'"',
                '>Start</',
            ], false)
            ->assertDontSee('Please check in to access this week');
    }

    public function test_without_a_check_in_the_current_week_asks_the_participant_to_check_in_on_the_dashboard(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                "Please check in to access this week's activities.",
                'href="'.route('dashboard').'">Check in on your dashboard</a>',
                '<span aria-disabled="true"',
                '>Start</span>',
            ], false)
            ->assertDontSee(route('training.activities.show', [$session, $week, $activity]), false);
    }

    public function test_each_session_page_shows_only_the_work_done_in_that_session(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Set up the repository']);
        $gate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'Week 1 checklist']);
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $otherSession = TrainingSession::factory()->forWeek($week)->completed()->withParticipants($participant)->create();
        ActivitySubmission::factory()->for($otherSession)->for($activity)->for($participant, 'participant')->create(['status' => 'approved']);
        GateEvaluation::factory()->for($gate, 'gate')->for($otherSession)->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        WeeklyVideoRecording::factory()->for($otherSession)->for($participant, 'participant')->for($week, 'week')->create(['name' => 'other-session.mp4']);

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Set up the repository</strong>',
                '>Not Started</span>',
                'Week 1 Gate · Week 1 checklist',
                'Status: <strong>Not ready</strong>',
                'href="'.route('training.gates.show', [$session, $week, $gate]).'"',
                'Not chosen',
                'href="'.route('training.video-recording.edit', [$session, $week]).'"',
            ], false)
            ->assertDontSee('>Approved</span>', false)
            ->assertDontSee('Incomplete')
            ->assertDontSee('other-session.mp4')
            ->assertDontSee('/training/sessions/'.$otherSession->id.'/', false);

        $this->actingAs($participant)->get(route('training.sessions.show', $otherSession))
            ->assertOk()
            ->assertSeeInOrder(['>Approved</span>', 'Status: <strong>Incomplete</strong>', 'other-session.mp4'], false);
    }

    public function test_gate_card_shows_the_saved_evaluation_status_or_the_readiness_status(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $evaluatedGate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'Evaluated Gate', 'position' => 1]);
        $readyGate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'Unevaluated Gate', 'position' => 2]);
        Activity::factory()->for($week, 'week')->create();
        GateEvaluation::factory()->for($evaluatedGate, 'gate')->for($session)->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'Week 2 Gate · Evaluated Gate', 'Status: <strong>Incomplete</strong>', 'href="'.route('training.gates.show', [$session, $week, $evaluatedGate]).'"',
                'Week 2 Gate · Unevaluated Gate', 'Status: <strong>Not ready</strong>', 'href="'.route('training.gates.show', [$session, $week, $readyGate]).'"',
            ], false)
            ->assertDontSee('Your Gate is ready for Mentor review.');
    }

    public function test_a_locked_week_shows_why_and_no_links_to_its_week_or_activities(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $weekOne = TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1]);
        $weekTwo = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        TrainingGate::factory()->for($weekOne, 'week')->create();
        $lockedActivity = Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Week two work']);
        $session = TrainingSession::factory()->for($program)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'id="session-tab-week-2"',
                '<span class="sr-only">(Locked)</span>',
                'Locked — Your mentor must mark the previous week completed before you can continue.',
                '<strong>Week two work</strong>',
            ], false)
            ->assertDontSee(route('training.weeks.show', [$session, $weekTwo]), false)
            ->assertDontSee(route('training.activities.show', [$session, $weekTwo, $lockedActivity]), false);
    }

    public function test_only_the_panel_of_a_week_whose_checklist_has_a_video_item_shows_the_video_row(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        [$weekOne, $weekTwo, $weekThree] = collect([1, 2, 3])
            ->map(fn (int $number): TrainingWeek => TrainingWeek::factory()->for($program)->create(['week_number' => $number, 'position' => $number]))
            ->all();
        $session = TrainingSession::factory()->forWeek($weekOne)->withParticipants($participant)->create();
        $weekOneGate = TrainingGate::factory()->for($weekOne, 'week')->create();
        GateCriterion::factory()->for($weekOneGate, 'gate')->create(['criterion_type' => 'deliverable']);
        $weekTwoGate = TrainingGate::factory()->for($weekTwo, 'week')->create();
        GateCriterion::factory()->for($weekTwoGate, 'gate')->create(['criterion_type' => 'weekly_video']);
        foreach ([$weekOneGate, $weekTwoGate] as $gate) {
            GateEvaluation::factory()->for($gate, 'gate')->for($session)->for($participant, 'participant')->create(['status' => GateEvaluationStatus::Passed]);
        }

        $html = $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Week 2 progress"',
                '>Video Recording</h3>',
                'Not chosen',
                'href="'.route('training.video-recording.edit', [$session, $weekTwo]).'"',
                '>Choose Video</',
                'aria-label="Week 3 progress"',
            ], false)
            ->assertDontSee(route('training.video-recording.edit', [$session, $weekOne]), false)
            ->assertDontSee(route('training.video-recording.edit', [$session, $weekThree]), false)
            ->getContent();

        $this->assertSame(1, substr_count($html, '>Video Recording</h3>'));
    }

    public function test_the_video_row_shows_the_chosen_video_with_its_preview_and_the_change_action(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $recording = WeeklyVideoRecording::factory()->for($session)->for($participant, 'participant')->for($week, 'week')->create(['name' => 'week-1-walkthrough.mp4']);

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Week 1 progress"',
                '>Video Recording</h3>',
                'Chosen',
                '>week-1-walkthrough.mp4</p>',
                'href="'.$recording->driveViewUrl().'"',
                'href="'.route('training.video-recording.edit', [$session, $week]).'"',
                '>Change Video</',
                'data-preview-src="'.$recording->drivePreviewUrl().'"',
                'unshared in your Google Drive. Choose it again.',
            ], false)
            ->assertDontSee('Not chosen')
            ->assertDontSee('>Choose Video</', false);
    }

    public function test_the_completion_banner_shows_only_once_every_week_of_this_session_is_completed(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create(['weeks_count' => 1]);
        $otherSession = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create(['weeks_count' => 1]);
        GateEvaluation::factory()->for($gate, 'gate')->for($otherSession)->for($participant, 'participant')->create(['status' => GateEvaluationStatus::Passed]);

        $this->actingAs($participant)->get(route('training.sessions.show', $session))->assertOk()->assertDontSee('Training complete');
        $this->actingAs($participant)->get(route('training.sessions.show', $otherSession))->assertOk()->assertSee('Training complete — every week completed.');
    }

    public function test_week_panels_show_no_daily_logs_training_guides_or_interviews(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 4, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Mock Level 3 Interview', 'position' => 2]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Week 4 progress"'], false)
            ->assertDontSee('Daily logs')
            ->assertDontSee('daily log')
            ->assertDontSee('Training Guide')
            ->assertDontSee('/training/guides/', false)
            ->assertDontSee('MENTOR MOCK INTERVIEW')
            ->assertDontSee('id="session-tab-mentoring"', false)
            ->assertDontSee('Waiting for Partner Assignment')
            ->assertDontSee('MOCK INTERVIEW PARTNERS')
            ->assertDontSee('Peer practice');
    }

    /**
     * Build a session of a new program with the given number of weeks, activities per week, gates and week resources,
     * fill it with participant progress, and count the queries of a session page request after a warm-up request.
     */
    private function sessionPageQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $session = TrainingSession::factory()->for($program)->withParticipants($participant)->create(['weeks_count' => 4]);

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position + 4 - $rows, 'position' => $position]);
            $gate = TrainingGate::factory()->for($week, 'week')->create();
            GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
            GateEvaluation::factory()->for($gate, 'gate')->for($session)->for($participant, 'participant')->create(['status' => $position < $rows ? 'passed' : 'not_ready']);
            WeeklyVideoRecording::factory()->for($session)->for($participant, 'participant')->for($week, 'week')->create();
            TrainingSessionResource::factory()->for($session)->googleDrive()->forWeek($week->week_number)->create();

            foreach (range(2, $rows + 1) as $activityPosition) {
                $activity = Activity::factory()->for($week, 'week')->create(['position' => $activityPosition]);
                $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create())->create();
                $submission = ActivitySubmission::factory()->for($session)->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
                $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
                SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
            }
        }

        $this->actingAs($participant)->get(route('training.sessions.show', $session))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('training.sessions.show', $session))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
