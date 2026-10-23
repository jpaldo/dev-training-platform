<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\Program;
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

        $passed = User::factory()->create(['name' => 'Pat Passed', 'email' => 'pat@example.test']);
        $passedEvaluation = GateEvaluation::factory()->for($gate, 'gate')->for($passed, 'participant')->create(['status' => 'passed']);
        GateCriterionEvaluation::factory()->for($passedEvaluation, 'gateEvaluation')->for($videoCriterion, 'criterion')->create(['status' => 'met']);
        GateCriterionEvaluation::factory()->for($passedEvaluation, 'gateEvaluation')->for($activityCriterion, 'criterion')->create(['status' => 'met']);
        $passedRecording = $this->recordWeeklyVideo($passed, $week);
        $this->submitActivity($passed, $activity);

        $awaiting = User::factory()->create(['name' => 'Ada Awaiting', 'email' => 'ada@example.test']);
        GateEvaluation::factory()->for($gate, 'gate')->for($awaiting, 'participant')->create(['status' => 'awaiting_review']);
        $awaitingRecording = $this->recordWeeklyVideo($awaiting, $week);
        $this->submitActivity($awaiting, $activity);

        $notReady = User::factory()->create(['name' => 'Nell Notready', 'email' => 'nell@example.test']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR · GATES',
                'Gate management',
                'TOTAL GATES', '>3</dd>',
                'READY FOR REVIEW', '>0</dd>',
                'AWAITING REVIEW', '>1</dd>',
                'COMPLETED', '>1</dd>',
                'NOT READY', '>1</dd>',
                'One row per participant and training Gate',
                '>Week 2</strong>', '>Release readiness</span>',
                'href="'.route('coach.participants.show', $awaiting).'"', '>Ada Awaiting</a>', '>ada@example.test</span>',
                'border-accent text-cyan-200 whitespace-nowrap">AWAITING REVIEW</span>',
                '>0 / 2</span>', '>criteria met</span>',
                'href="'.route('training.video-recording.view', [$week, $awaitingRecording]).'"', '>Awaiting Review</a>',
                '>—</span>',
                'href="'.route('training.coach.gates.show', [$week, $awaiting, $gate]).'"', '>Review Gate</a>',
                '>Nell Notready</a>',
                'border-line text-muted whitespace-nowrap">NOT READY</span>',
                '>0 / 2</span>',
                '>Missing</span>',
                '>2 blockers</strong>',
                'href="'.route('training.coach.gates.show', [$week, $notReady, $gate]).'"', '>Review Gate</a>',
                '>Pat Passed</a>',
                'border-emerald-400 text-emerald-200 whitespace-nowrap">COMPLETED</span>',
                '>2 / 2</span>',
                'href="'.route('training.video-recording.view', [$week, $passedRecording]).'"', '>✓ Uploaded</a>',
                '>—</span>',
                'href="'.route('training.coach.gates.show', [$week, $passed, $gate]).'"', '>View Gate</a>',
            ], false)
            ->assertDontSee('No Gate rows match these filters.')
            ->assertDontSee('Daily Logs')
            ->assertDontSee('QA Path')
            ->assertDontSee('name="path"', false);
    }

    public function test_a_gate_without_a_weekly_video_criterion_shows_the_video_as_not_required_and_no_video_blocker(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create();
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $ready = User::factory()->create(['name' => 'Ada Ready']);
        $this->submitActivity($ready, $activity);
        User::factory()->create(['name' => 'Bo Blocked']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder(['>Ada Ready</a>', 'READY FOR REVIEW</span>', '>Not required</span>', '>—</span>', '>Review Gate</a>'], false)
            ->assertSeeInOrder(['>Bo Blocked</a>', 'NOT READY</span>', '>Not required</span>', '>1 blocker</strong>'], false);
    }

    public function test_filters_keep_their_values_and_the_pagination_links_keep_them(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        TrainingGate::factory()->for($week, 'week')->create();
        Activity::factory()->for($week, 'week')->create(['position' => 1]);
        TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create(['title' => 'Week three gate']);
        User::factory()->count(26)->sequence(fn (Sequence $sequence): array => ['name' => 'Page Person '.($sequence->index + 1)])->create();
        User::factory()->create(['name' => 'Other Person']);
        $filters = ['search' => 'Page', 'week' => 2, 'status' => 'not_ready'];
        $coach = User::factory()->create(['role' => 'coach']);

        $firstPage = $this->actingAs($coach)->get(route('coach.gates', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="not_ready" selected>Not Ready</option>', false)
            ->assertSee('>26</dd>', false)
            ->assertDontSee('Other Person')
            ->assertDontSee('Week three gate')
            ->assertSee(e(route('coach.gates', [...$filters, 'page' => 2])), false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-gate-row'));

        $secondPage = $this->actingAs($coach)->get(route('coach.gates', [...$filters, 'page' => 2]))->assertOk();

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-gate-row'));
    }

    public function test_an_old_path_filter_is_ignored(): void
    {
        TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create();
        User::factory()->create(['name' => 'Mia Manual']);
        User::factory()->create(['name' => 'Abe Automation']);

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('Mia Manual')
            ->assertSee('Abe Automation');

        $this->assertSame(2, substr_count($response->getContent(), 'data-gate-row'));
    }

    public function test_week_one_gates_are_listed_and_the_week_filter_offers_every_week_of_the_program(): void
    {
        $program = Program::factory()->create();
        $weeks = collect(range(1, 4))->mapWithKeys(fn (int $weekNumber): array => [
            $weekNumber => TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]),
        ]);
        $weekOneGate = TrainingGate::factory()->for($weeks[1], 'week')->create(['title' => 'Week one gate']);
        TrainingGate::factory()->for($weeks[2], 'week')->create(['title' => 'Week two gate']);
        $participant = User::factory()->create(['name' => 'Wendy WeekOne']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates', ['week' => 1]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSeeInOrder([
                '<option value="1" selected>Week 1</option>', '<option value="2" >Week 2</option>', '<option value="3" >Week 3</option>', '<option value="4" >Week 4</option>',
                '>Week 1</strong>', '>Week one gate</span>', '>Wendy WeekOne</a>',
                'href="'.route('training.coach.gates.show', [$weeks[1], $participant, $weekOneGate]).'"', '>Review Gate</a>',
            ], false)
            ->assertDontSee('Week two gate');
    }

    public function test_rows_are_sorted_from_ready_for_review_to_passed(): void
    {
        $participants = $this->participantInEveryStatus();

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder([
                'READY FOR REVIEW', '>1</dd>',
                'AWAITING REVIEW', '>1</dd>',
                'COMPLETED', '>1</dd>',
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
    public function test_status_filter_keeps_only_the_rows_with_that_status(GateEvaluationStatus $status, string $option, string $badge, string $action): void
    {
        $participants = $this->participantInEveryStatus();

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.gates', ['status' => $status->value]))
            ->assertOk()
            ->assertSee('<option value="'.$status->value.'" selected>'.$option.'</option>', false)
            ->assertSeeInOrder([$participants[$status->value]->name, $badge, $action], false);

        foreach (collect($participants)->except($status->value) as $otherParticipant) {
            $response->assertDontSee($otherParticipant->name);
        }
        $this->assertSame(1, substr_count($response->getContent(), 'data-gate-row'));
    }

    /**
     * The status filter option text, and the badge and action link of a row with the given Gate status.
     *
     * @return array<string, array{GateEvaluationStatus, string, string, string}>
     */
    public static function statusRows(): array
    {
        return [
            'not ready' => [GateEvaluationStatus::NotReady, 'Not Ready', 'border-line text-muted whitespace-nowrap">NOT READY</span>', '>Review Gate</a>'],
            'ready for review' => [GateEvaluationStatus::ReadyForReview, 'Ready for Review', 'border-accent text-cyan-200 whitespace-nowrap">READY FOR REVIEW</span>', '>Review Gate</a>'],
            'awaiting review' => [GateEvaluationStatus::AwaitingReview, 'Awaiting Review', 'border-accent text-cyan-200 whitespace-nowrap">AWAITING REVIEW</span>', '>Review Gate</a>'],
            'needs revision' => [GateEvaluationStatus::NeedsRevision, 'Incomplete', 'border-amber-500 text-amber-200 whitespace-nowrap">INCOMPLETE</span>', '>Review Gate</a>'],
            'failed' => [GateEvaluationStatus::Failed, 'Failed', 'border-danger text-rose-200 whitespace-nowrap">FAILED</span>', '>Review Gate</a>'],
            'passed' => [GateEvaluationStatus::Passed, 'Completed', 'border-emerald-400 text-emerald-200 whitespace-nowrap">COMPLETED</span>', '>View Gate</a>'],
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
     * (not ready), one with the activity submitted and the weekly video (ready for review), and one
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
}
