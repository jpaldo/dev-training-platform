<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachGateBoardPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_summary_and_one_row_per_participant_and_gate_sorted_by_status(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'Release readiness']);
        $videoCriterion = GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
        $activityCriterion = GateCriterion::factory()->for($gate, 'gate')->create();
        GateCriterion::factory()->for($gate, 'gate')->create(['is_required' => false]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);

        $passed = User::factory()->create(['name' => 'Pat Passed', 'email' => 'pat@example.test', 'training_path' => 'manual']);
        $passedEvaluation = GateEvaluation::factory()->for($gate, 'gate')->for($passed, 'participant')->create(['status' => 'passed']);
        GateCriterionEvaluation::factory()->for($passedEvaluation, 'gateEvaluation')->for($videoCriterion, 'criterion')->create(['status' => 'met']);
        GateCriterionEvaluation::factory()->for($passedEvaluation, 'gateEvaluation')->for($activityCriterion, 'criterion')->create(['status' => 'met']);
        $passedRecording = $this->recordWeeklyVideo($passed, $week);
        $this->submitActivity($passed, $activity);
        $this->logDays($passed, $activity, 5);

        $awaiting = User::factory()->create(['name' => 'Ada Awaiting', 'email' => 'ada@example.test', 'training_path' => 'automation']);
        GateEvaluation::factory()->for($gate, 'gate')->for($awaiting, 'participant')->create(['status' => 'awaiting_review']);
        $awaitingRecording = $this->recordWeeklyVideo($awaiting, $week);
        $this->submitActivity($awaiting, $activity);
        $this->logDays($awaiting, $activity, 4);

        $notReady = User::factory()->create(['name' => 'Nell Notready', 'email' => 'nell@example.test', 'training_path' => 'manual']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH · GATES',
                'Gate management',
                'TOTAL GATES', '>3</dd>',
                'READY FOR REVIEW', '>0</dd>',
                'AWAITING REVIEW', '>1</dd>',
                'PASSED', '>1</dd>',
                'NOT READY', '>1</dd>',
                'One row per participant and training Gate',
                '>Week 2</strong>', '>Release readiness</span>',
                'href="'.route('coach.participants.show', $awaiting).'"', '>Ada Awaiting</a>', '>ada@example.test</span>',
                '>Automation</td>',
                'border-accent text-cyan-200 whitespace-nowrap">AWAITING REVIEW</span>',
                '>4 / 5</span>', '>1 remaining</span>',
                '>0 / 2</span>', '>criteria met</span>',
                'href="'.route('training.video-recording.view', [$week, $awaitingRecording]).'"', '>Awaiting Review</a>',
                '>1 blocker</strong>',
                'href="'.route('training.coach.gates.show', [$week, $awaiting, $gate]).'"', '>Review Gate</a>',
                '>Nell Notready</a>',
                'border-line text-muted whitespace-nowrap">NOT READY</span>',
                '>0 / 5</span>', '>5 remaining</span>',
                '>0 / 2</span>',
                '>Missing</span>',
                '>3 blockers</strong>',
                'href="'.route('training.coach.gates.show', [$week, $notReady, $gate]).'"', '>Review Gate</a>',
                '>Pat Passed</a>',
                '>Manual</td>',
                'border-emerald-400 text-emerald-200 whitespace-nowrap">PASSED</span>',
                '>5 / 5</span>', '>Complete</span>',
                '>2 / 2</span>',
                'href="'.route('training.video-recording.view', [$week, $passedRecording]).'"', '>✓ Uploaded</a>',
                '>—</span>',
                'href="'.route('training.coach.gates.show', [$week, $passed, $gate]).'"', '>View Gate</a>',
            ], false)
            ->assertDontSee('No Gate rows match these filters.');
    }

    public function test_filters_keep_their_values_and_the_pagination_links_keep_them(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        TrainingGate::factory()->for($week, 'week')->create();
        TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create(['title' => 'Week three gate']);
        User::factory()->count(26)->sequence(fn (Sequence $sequence): array => ['name' => 'Page Person '.($sequence->index + 1)])->create(['training_path' => 'manual']);
        User::factory()->create(['name' => 'Page Automation', 'training_path' => 'automation']);
        $filters = ['search' => 'Page', 'week' => 2, 'path' => 'manual', 'status' => 'not_ready'];
        $coach = User::factory()->create(['role' => 'coach']);

        $firstPage = $this->actingAs($coach)->get(route('coach.gates', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="manual" selected>Manual QA</option>', false)
            ->assertSee('<option value="not_ready" selected>Not Ready</option>', false)
            ->assertSee('>26</dd>', false)
            ->assertDontSee('Page Automation')
            ->assertDontSee('Week three gate')
            ->assertSee(e(route('coach.gates', [...$filters, 'page' => 2])), false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-gate-row'));

        $secondPage = $this->actingAs($coach)->get(route('coach.gates', [...$filters, 'page' => 2]))->assertOk();

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-gate-row'));
    }

    public function test_rows_are_sorted_from_ready_for_review_to_passed(): void
    {
        $participants = $this->participantInEveryStatus();

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder([
                'READY FOR REVIEW', '>1</dd>',
                'AWAITING REVIEW', '>1</dd>',
                'PASSED', '>1</dd>',
                'NOT READY', '>1</dd>',
                $participants['ready_for_review']->name,
                $participants['awaiting_review']->name,
                $participants['needs_revision']->name,
                $participants['not_ready']->name,
                $participants['failed']->name,
                $participants['passed']->name,
            ], false);
    }

    #[DataProvider('statusRows')]
    public function test_status_filter_keeps_only_the_rows_with_that_status(GateEvaluationStatus $status, string $badge, string $action): void
    {
        $participants = $this->participantInEveryStatus();

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates', ['status' => $status->value]))
            ->assertOk()
            ->assertSee('<option value="'.$status->value.'" selected>', false)
            ->assertSeeInOrder([$participants[$status->value]->name, $badge, $action], false);

        foreach (collect($participants)->except($status->value) as $otherParticipant) {
            $response->assertDontSee($otherParticipant->name);
        }
        $this->assertSame(1, substr_count($response->getContent(), 'data-gate-row'));
    }

    /**
     * The badge and action link of a row with the given Gate status.
     *
     * @return array<string, array{GateEvaluationStatus, string, string}>
     */
    public static function statusRows(): array
    {
        return [
            'not ready' => [GateEvaluationStatus::NotReady, 'border-line text-muted whitespace-nowrap">NOT READY</span>', '>Review Gate</a>'],
            'ready for review' => [GateEvaluationStatus::ReadyForReview, 'border-accent text-cyan-200 whitespace-nowrap">READY FOR REVIEW</span>', '>Review Gate</a>'],
            'awaiting review' => [GateEvaluationStatus::AwaitingReview, 'border-accent text-cyan-200 whitespace-nowrap">AWAITING REVIEW</span>', '>Review Gate</a>'],
            'needs revision' => [GateEvaluationStatus::NeedsRevision, 'border-amber-500 text-amber-200 whitespace-nowrap">NEEDS REVISION</span>', '>Review Gate</a>'],
            'failed' => [GateEvaluationStatus::Failed, 'border-danger text-rose-200 whitespace-nowrap">FAILED</span>', '>Review Gate</a>'],
            'passed' => [GateEvaluationStatus::Passed, 'border-emerald-400 text-emerald-200 whitespace-nowrap">PASSED</span>', '>View Gate</a>'],
        ];
    }

    public function test_status_filter_rejects_a_value_that_is_not_a_gate_status(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates', ['status' => 'approved']))
            ->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
    }

    public function test_board_without_matching_rows_shows_a_notice(): void
    {
        TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create();

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates', ['search' => 'Nobody']))
            ->assertOk()
            ->assertSee('No Gate rows match these filters.')
            ->assertSeeInOrder(['TOTAL GATES', '>0</dd>'], false);

        $this->assertSame(0, substr_count($response->getContent(), 'data-gate-row'));
    }

    /**
     * Create a Week 2 Gate with a weekly video criterion and one participant in each Gate status: one with nothing done
     * (not ready), one with the activity submitted, five daily logs and the weekly video (ready for review), and one
     * with a saved evaluation for each other status.
     *
     * @return array<string, User> participants keyed by their Gate status value
     */
    private function participantInEveryStatus(): array
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $participants = [];
        foreach (GateEvaluationStatus::cases() as $status) {
            $participants[$status->value] = User::factory()->create(['name' => 'Participant '.$status->label()]);
        }
        $this->submitActivity($participants['ready_for_review'], $activity);
        $this->logDays($participants['ready_for_review'], $activity, 5);
        $this->recordWeeklyVideo($participants['ready_for_review'], $week);
        foreach ([GateEvaluationStatus::AwaitingReview, GateEvaluationStatus::NeedsRevision, GateEvaluationStatus::Failed, GateEvaluationStatus::Passed] as $status) {
            GateEvaluation::factory()->for($gate, 'gate')->for($participants[$status->value], 'participant')->create(['status' => $status]);
        }

        return $participants;
    }

    /**
     * Store an uploaded Weekly Video Recording of the participant for the week.
     */
    private function recordWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'path' => "videos/{$participant->id}.mp4", 'original_filename' => 'week-2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1048576, 'uploaded_at' => now()]);
    }

    /**
     * Create a submitted submission of the activity, with a submitted version, by the participant.
     */
    private function submitActivity(User $participant, Activity $activity): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
    }

    /**
     * Create the given number of daily logs of the participant on the activity, one per day.
     */
    private function logDays(User $participant, Activity $activity, int $days): void
    {
        foreach (range(1, $days) as $day) {
            DailyLog::factory()->for($participant, 'participant')->for($activity)->create(['logged_on' => now()->subDays($day)->toDateString()]);
        }
    }
}
