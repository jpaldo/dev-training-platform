<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
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

    public function test_a_mentor_sees_the_summary_and_one_row_per_participant_and_gate_sorted_by_status(): void
    {
        $program = Program::factory()->create(['name' => 'November training']);
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2]);
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
        $mentor = User::factory()->create(['role' => 'coach']);
        $this->mentor($program, $mentor, $passed, $awaiting, $notReady);

        $this->actingAs($mentor)->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR · GATES',
                'Gate management',
                'TOTAL GATES', '>3</dd>',
                'READY FOR REVIEW', '>0</dd>',
                'AWAITING REVIEW', '>1</dd>',
                'COMPLETED', '>1</dd>',
                'NOT READY', '>1</dd>',
                'One row per participant, curriculum and Gate',
                '>Week 2</strong>', '>Release readiness</span>',
                'href="'.route('coach.participants.show', [$program, $awaiting]).'"', '>Ada Awaiting</a>', '>ada@example.test</span>',
                '>November training</span>', '>'.$program->dateRangeLabel().'</span>',
                'border-accent text-cyan-200 whitespace-nowrap">AWAITING REVIEW</span>',
                '>0 / 2</span>', '>criteria met</span>',
                'href="'.$awaitingRecording->driveViewUrl().'" target="_blank" rel="noopener noreferrer"', '>Awaiting Review<span class="sr-only"> (opens Google Drive in a new tab)</span></a>',
                '>—</span>',
                'href="'.route('training.coach.gates.show', [$program, $week, $awaiting, $gate]).'"', '>Review Gate</a>',
                '>Nell Notready</a>',
                'border-line text-muted whitespace-nowrap">NOT READY</span>',
                '>0 / 2</span>',
                '>Missing</span>',
                '>2 blockers</strong>',
                'href="'.route('training.coach.gates.show', [$program, $week, $notReady, $gate]).'"', '>Review Gate</a>',
                '>Pat Passed</a>',
                'border-emerald-400 text-emerald-200 whitespace-nowrap">COMPLETED</span>',
                '>2 / 2</span>',
                'href="'.$passedRecording->driveViewUrl().'" target="_blank" rel="noopener noreferrer"', '>✓ Chosen<span class="sr-only"> (opens Google Drive in a new tab)</span></a>',
                '>—</span>',
                'href="'.route('training.coach.gates.show', [$program, $week, $passed, $gate]).'"', '>View Gate</a>',
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
        $this->join($week->program, $ready, User::factory()->create(['name' => 'Bo Blocked']));

        $this->actingAs($this->admin())->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder(['>Ada Ready</a>', 'READY FOR REVIEW</span>', '>Not required</span>', '>—</span>', '>Review Gate</a>'], false)
            ->assertSeeInOrder(['>Bo Blocked</a>', 'NOT READY</span>', '>Not required</span>', '>1 blocker</strong>'], false);
    }

    public function test_criteria_met_counts_only_the_items_still_on_the_checklist(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criteria = GateCriterion::factory()->count(5)->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
        $participant = User::factory()->create(['name' => 'Pat Passed']);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        foreach ($criteria as $criterion) {
            GateCriterionEvaluation::factory()->for($evaluation, 'gateEvaluation')->for($criterion, 'criterion')->create(['status' => 'met']);
        }
        $criteria->last()->update(['is_required' => false]);
        $this->join($week->program, $participant);

        $this->actingAs($this->admin())->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder(['>Pat Passed</a>', '>4 / 4</span>', '>criteria met</span>'], false)
            ->assertDontSee('>5 / 4</span>', false);
    }

    public function test_filters_keep_their_values_and_the_pagination_links_keep_them(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        TrainingGate::factory()->for($week, 'week')->create();
        Activity::factory()->for($week, 'week')->create(['position' => 1]);
        TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create(['title' => 'Week three gate']);
        $program = $week->program;
        $this->join($program, ...User::factory()->count(26)->sequence(fn (Sequence $sequence): array => ['name' => 'Page Person '.($sequence->index + 1)])->create());
        $this->join($program, User::factory()->create(['name' => 'Other Person']));
        $filters = ['search' => 'Page', 'program' => $program->id, 'week' => 2, 'status' => 'not_ready'];
        $admin = $this->admin();

        $firstPage = $this->actingAs($admin)->get(route('coach.gates', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertSee('<option value="'.$program->id.'" selected>'.e($this->optionLabel($program)).'</option>', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="not_ready" selected>Not Ready</option>', false)
            ->assertSee('>26</dd>', false)
            ->assertDontSee('Other Person')
            ->assertDontSee('Week three gate')
            ->assertSee(e(route('coach.gates', [...$filters, 'page' => 2])), false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-gate-row'));

        $secondPage = $this->actingAs($admin)->get(route('coach.gates', [...$filters, 'page' => 2]))->assertOk();

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-gate-row'));
    }

    public function test_an_old_path_filter_is_ignored(): void
    {
        $gate = TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create();
        $this->join(
            $gate->week->program,
            User::factory()->create(['name' => 'Mia Manual']),
            User::factory()->create(['name' => 'Abe Automation']),
        );

        $response = $this->actingAs($this->admin())->get(route('coach.gates', ['path' => 'automation']))
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
        $this->join($program, $participant);

        $this->actingAs($this->admin())->get(route('coach.gates', ['week' => 1]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSeeInOrder([
                '<option value="1" selected>Week 1</option>', '<option value="2" >Week 2</option>', '<option value="3" >Week 3</option>', '<option value="4" >Week 4</option>',
                '>Week 1</strong>', '>Week one gate</span>', '>Wendy WeekOne</a>',
                'href="'.route('training.coach.gates.show', [$program, $weeks[1], $participant, $weekOneGate]).'"', '>Review Gate</a>',
            ], false)
            ->assertDontSee('Week two gate');
    }

    public function test_each_curriculum_membership_gets_its_own_row_with_that_curriculums_status_and_link(): void
    {
        $weekA = TrainingWeek::factory()->for(Program::factory()->state(['name' => 'Curriculum A']))->create(['week_number' => 2]);
        $gateA = TrainingGate::factory()->for($weekA, 'week')->create(['title' => 'Release readiness']);
        Activity::factory()->for($weekA, 'week')->create(['position' => 1]);
        $weekB = TrainingWeek::factory()->for(Program::factory()->state(['name' => 'Curriculum B']))->create(['week_number' => 2]);
        $gateB = TrainingGate::factory()->for($weekB, 'week')->create(['title' => 'Release readiness']);
        Activity::factory()->for($weekB, 'week')->create(['position' => 1]);
        $inactiveWeek = TrainingWeek::factory()->create(['week_number' => 2, 'is_active' => false]);
        TrainingGate::factory()->for($inactiveWeek, 'week')->create();
        $participant = User::factory()->create(['name' => 'Sam Twocurricula']);
        $this->join($weekA->program, $participant);
        $this->join($weekB->program, $participant);
        $this->join($inactiveWeek->program, User::factory()->create(['name' => 'Wes Inactiveweek']));
        User::factory()->create(['name' => 'Nina Nocurriculum']);
        GateEvaluation::factory()->for($gateA, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $response = $this->actingAs($this->admin())->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder(['TOTAL GATES', '>2</dd>', 'COMPLETED', '>1</dd>', 'NOT READY', '>1</dd>'], false)
            ->assertSeeInOrder([
                '>Sam Twocurricula</a>', '>Curriculum B</span>', 'NOT READY</span>', 'href="'.route('training.coach.gates.show', [$weekB->program, $weekB, $participant, $gateB]).'"', '>Review Gate</a>',
                '>Sam Twocurricula</a>', '>Curriculum A</span>', 'COMPLETED</span>', 'href="'.route('training.coach.gates.show', [$weekA->program, $weekA, $participant, $gateA]).'"', '>View Gate</a>',
            ], false)
            ->assertDontSee('Wes Inactiveweek')
            ->assertDontSee('Nina Nocurriculum');

        $this->assertSame(2, substr_count($response->getContent(), 'data-gate-row'));
    }

    public function test_rows_are_sorted_from_ready_for_review_to_passed(): void
    {
        $participants = $this->participantInEveryStatus();

        $this->actingAs($this->admin())->get(route('coach.gates'))
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

        $response = $this->actingAs($this->admin())->get(route('coach.gates', ['status' => $status->value]))
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
        $this->actingAs($this->admin())->get(route('coach.gates', ['status' => 'approved']))
            ->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
    }

    public function test_board_without_matching_rows_shows_a_notice(): void
    {
        TrainingGate::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create();

        $response = $this->actingAs($this->admin())->get(route('coach.gates', ['search' => 'Nobody']))
            ->assertOk()
            ->assertSee('No Gate rows match these filters.')
            ->assertSeeInOrder(['TOTAL GATES', '>0</dd>'], false);

        $this->assertSame(0, substr_count($response->getContent(), 'data-gate-row'));
    }

    public function test_a_mentor_sees_only_the_rows_of_their_current_participants_in_that_curriculum(): void
    {
        ['programX' => $programX, 'programY' => $programY, 'weekX' => $weekX, 'gateX' => $gateX, 'weekY' => $weekY, 'gateY' => $gateY, 'pat' => $pat, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy] = $this->mentorScopeFixture();

        $benResponse = $this->actingAs($ben)->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder(['TOTAL GATES', '>1</dd>'], false)
            ->assertSee('<option value="'.$programX->id.'" >'.e($this->optionLabel($programX)).'</option>', false)
            ->assertSeeInOrder(['>Pat Participant</a>', '>Curriculum X</span>', 'href="'.route('training.coach.gates.show', [$programX, $weekX, $pat, $gateX]).'"'], false)
            ->assertDontSee('Curriculum Y')
            ->assertDontSee('Quinn Other');
        $this->assertSame(1, substr_count($benResponse->getContent(), 'data-gate-row'));

        $cyResponse = $this->actingAs($cy)->get(route('coach.gates'))
            ->assertOk()
            ->assertSeeInOrder(['>Pat Participant</a>', '>Curriculum Y</span>', 'href="'.route('training.coach.gates.show', [$programY, $weekY, $pat, $gateY]).'"'], false)
            ->assertDontSee('Curriculum X')
            ->assertDontSee('Quinn Other');
        $this->assertSame(1, substr_count($cyResponse->getContent(), 'data-gate-row'));

        $this->actingAs($ana)->get(route('coach.gates'))
            ->assertOk()
            ->assertSee('No Gate rows match these filters.')
            ->assertDontSee('Pat Participant');
    }

    public function test_an_admin_sees_every_membership_and_the_curriculum_filter_narrows_them(): void
    {
        ['programY' => $programY] = $this->mentorScopeFixture();
        $admin = $this->admin();

        $everyRow = $this->actingAs($admin)->get(route('coach.gates'))->assertOk();
        $this->assertSame(3, substr_count($everyRow->getContent(), 'data-gate-row'));

        $programYRows = $this->actingAs($admin)->get(route('coach.gates', ['program' => $programY->id]))
            ->assertOk()
            ->assertSee('<option value="'.$programY->id.'" selected>'.e($this->optionLabel($programY)).'</option>', false)
            ->assertSeeInOrder(['>Pat Participant</a>', '>Curriculum Y</span>'], false)
            ->assertDontSee('Quinn Other');
        $this->assertSame(1, substr_count($programYRows->getContent(), 'data-gate-row'));
    }

    public function test_an_unknown_curriculum_fails_validation(): void
    {
        $this->actingAs($this->admin())->get(route('coach.gates', ['program' => 999]))
            ->assertSessionHasErrors(['program']);
    }

    /**
     * Two curricula X and Y, each with a Week 2 Gate. In X an admin assigned Ana and then Ben as Pat's mentor, and Dan
     * mentors Quinn; in Y Cy mentors Pat.
     *
     * @return array{programX: Program, programY: Program, weekX: TrainingWeek, gateX: TrainingGate, weekY: TrainingWeek, gateY: TrainingGate, pat: User, ana: User, ben: User, cy: User}
     */
    private function mentorScopeFixture(): array
    {
        $programX = Program::factory()->create(['name' => 'Curriculum X']);
        $programY = Program::factory()->create(['name' => 'Curriculum Y']);
        $weekX = TrainingWeek::factory()->for($programX)->create(['week_number' => 2]);
        $weekY = TrainingWeek::factory()->for($programY)->create(['week_number' => 2]);
        $gateX = TrainingGate::factory()->for($weekX, 'week')->create();
        $gateY = TrainingGate::factory()->for($weekY, 'week')->create();
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        [$ana, $ben, $cy, $dan] = User::factory()->count(4)->create(['role' => 'coach'])->all();
        $this->mentor($programX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($programX, $ben, $pat);
        $this->mentor($programX, $dan, $quinn);
        $this->mentor($programY, $cy, $pat);

        return compact('programX', 'programY', 'weekX', 'gateX', 'weekY', 'gateY', 'pat', 'ana', 'ben', 'cy');
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
        $this->join($week->program, ...array_values($participants));
        $this->submitActivity($participants['ready_for_review'], $activity);
        $this->recordWeeklyVideo($participants['ready_for_review'], $week);
        foreach ([GateEvaluationStatus::AwaitingReview, GateEvaluationStatus::NeedsRevision, GateEvaluationStatus::Failed, GateEvaluationStatus::Passed] as $status) {
            GateEvaluation::factory()->for($gate, 'gate')->for($participants[$status->value], 'participant')->create(['status' => $status]);
        }

        return $participants;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * The curriculum filter's option text for the curriculum: its name and dates.
     */
    private function optionLabel(Program $program): string
    {
        return $program->name.' ('.$program->dateRangeLabel().')';
    }

    /**
     * Add the coach to the curriculum's mentors and the participants to the curriculum, with the coach as their
     * current mentor there.
     */
    private function mentor(Program $program, User $coach, User ...$participants): void
    {
        $program->mentors()->syncWithoutDetaching([$coach->id]);
        $this->join($program, ...$participants);
        foreach ($participants as $participant) {
            MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * Add the participants to the curriculum.
     */
    private function join(Program $program, User ...$participants): void
    {
        foreach ($participants as $participant) {
            $program->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        }
    }

    /**
     * Store a chosen Weekly Video Recording of the participant for the week.
     */
    private function recordWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();
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
