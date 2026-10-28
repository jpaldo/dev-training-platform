<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrainingWeekPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participant_without_a_video_sees_the_gate_activities_and_a_single_choose_main_action(): void
    {
        $participant = User::factory()->create();
        ['program' => $program, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->weekFixture(rows: 2, participant: $participant);
        Activity::factory()->for($week, 'week')->create(['title' => 'Retired activity', 'is_active' => false, 'position' => 3]);
        [$firstActivity, $secondActivity] = $activities;

        $response = $this->actingAs($participant)->get(route('training.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2',
                e($week->title),
                e($week->description),
                'id="week-main-action"',
                'href="'.route('training.video-recording.edit', [$program, $week]).'"',
                'Choose Weekly Video →',
                e($gate->title),
                'Gate status:',
                'NOT READY',
                'aria-label="Checklist items"',
                e($criteria[0]->title),
                'Awaiting Review',
                e($criteria[0]->description),
                e($criteria[1]->title),
                'Awaiting Review',
                e($criteria[1]->description),
                'Mentor approval is required before the next week unlocks.',
                'href="'.route('training.gates.show', [$program, $week, $gate]).'"',
                'View Gate',
                'ACTIVITY 1',
                e($firstActivity->title),
                'href="'.route('training.activities.show', [$program, $week, $firstActivity]).'"',
                'View activity →',
                'ACTIVITY 2',
                e($secondActivity->title),
                'href="'.route('training.activities.show', [$program, $week, $secondActivity]).'"',
                'View activity →',
                'WEEKLY REQUIREMENT',
                'Video Recording',
                'Status:',
                'Not chosen',
                'href="'.route('training.programs.show', $program).'#week-'.$week->week_number.'"',
                'Back',
            ], false)
            ->assertDontSee('Optional stretch goal')
            ->assertDontSee('Retired activity')
            ->assertDontSee('✓')
            ->assertDontSee('Change Video')
            ->assertDontSee('Content coming in a later phase');

        $this->assertSame(1, substr_count($response->getContent(), 'Choose Weekly Video'));
    }

    public function test_participant_with_a_video_and_an_evaluated_gate_sees_the_ticked_checklist_items_and_no_main_action(): void
    {
        $participant = User::factory()->create();
        ['program' => $program, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->weekFixture(rows: 2, participant: $participant);
        $recording = $this->chooseWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criteria[0]->id, 'status' => 'met']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criteria[1]->id, 'status' => 'not_met']);

        $response = $this->actingAs($participant)->get(route('training.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'Gate status:',
                'INCOMPLETE',
                'font-black border-emerald-400 bg-emerald-400 text-canvas"',
                '>✓</span>',
                e($criteria[0]->title),
                'Met',
                'font-black border-line"',
                e($criteria[1]->title),
                'Missing',
                'Status:',
                'Chosen',
                e($recording->name),
                $recording->driveViewUrl(),
                'href="'.route('training.video-recording.edit', [$program, $week]).'"',
                'Change Video',
                'data-preview-src="'.$recording->drivePreviewUrl().'"',
                'unshared in your Google Drive. Choose it again.',
            ], false)
            ->assertDontSee('week-main-action')
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('Choose Weekly Video');

        $this->assertSame(1, substr_count($response->getContent(), '✓'));
    }

    public function test_ready_participant_without_an_evaluation_sees_the_first_gate_by_position_ready_for_review(): void
    {
        $participant = User::factory()->create();
        ['program' => $program, 'week' => $week, 'gate' => $laterGate, 'activities' => $activities] = $this->weekFixture(rows: 1, participant: $participant);
        $laterGate->update(['title' => 'Later Gate', 'position' => 2]);
        $firstGate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'First Gate', 'position' => 1]);
        GateCriterion::factory()->for($firstGate, 'gate')->create(['criterion_type' => 'weekly_video', 'title' => 'Required Weekly Video Recording']);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activities->first())->create(['status' => 'submitted']);
        $this->chooseWeeklyVideo($participant, $week);

        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'First Gate',
                'Gate status:',
                'READY FOR REVIEW',
                'Required Weekly Video Recording',
                'href="'.route('training.gates.show', [$program, $week, $firstGate]).'"',
                'View Gate',
            ], false)
            ->assertDontSee('Later Gate')
            ->assertDontSee('NOT READY');
    }

    public function test_only_a_week_whose_checklist_has_a_video_item_shows_the_video_card_and_choose_action(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        [$weekOne, $weekTwo, $weekThree] = collect([1, 2, 3])
            ->map(fn (int $number): TrainingWeek => TrainingWeek::factory()->for($program)->create(['week_number' => $number, 'position' => $number]))
            ->all();
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $weekOneGate = TrainingGate::factory()->for($weekOne, 'week')->create();
        GateCriterion::factory()->for($weekOneGate, 'gate')->create(['criterion_type' => 'deliverable']);
        $weekTwoGate = TrainingGate::factory()->for($weekTwo, 'week')->create();
        GateCriterion::factory()->for($weekTwoGate, 'gate')->create(['criterion_type' => 'weekly_video']);
        foreach ([$weekOneGate, $weekTwoGate] as $gate) {
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        }

        $weekOnePage = $this->actingAs($participant)->get(route('training.weeks.show', [$program, $weekOne]))
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-main-action"',
                'href="'.route('training.gates.show', [$program, $weekOne, $weekOneGate]).'"',
                'View Gate →',
                e($weekOneGate->title),
            ], false)
            ->assertDontSee('WEEKLY REQUIREMENT')
            ->assertDontSee('Choose Weekly Video')
            ->assertDontSee(route('training.video-recording.edit', [$program, $weekOne]), false);
        $this->assertSame(1, substr_count($weekOnePage->getContent(), 'href="'.route('training.gates.show', [$program, $weekOne, $weekOneGate]).'"'));

        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $weekTwo]))
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-main-action"',
                'href="'.route('training.video-recording.edit', [$program, $weekTwo]).'"',
                'Choose Weekly Video →',
                'WEEKLY REQUIREMENT',
                'Video Recording',
                'Not chosen',
            ], false);

        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $weekThree]))
            ->assertOk()
            ->assertDontSee('week-main-action')
            ->assertDontSee('WEEKLY REQUIREMENT')
            ->assertDontSee('Choose Weekly Video')
            ->assertDontSee(route('training.video-recording.edit', [$program, $weekThree]), false);
    }

    public function test_coach_sees_the_gate_not_ready_without_a_view_gate_link(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        ['program' => $program, 'week' => $week, 'gate' => $gate] = $this->weekFixture(rows: 1);
        $program->mentors()->attach($coach->id);

        $this->actingAs($coach)->get(route('training.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([e($gate->title), 'Gate status:', 'NOT READY', 'Mentor approval is required before the next week unlocks.'], false)
            ->assertDontSee('View Gate')
            ->assertDontSee(route('training.gates.show', [$program, $week, $gate]), false);
    }

    public function test_coach_sees_no_video_card_or_main_action_and_goes_back_to_the_profile(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 5, 'position' => 5]);
        $program = $week->program;
        $program->mentors()->attach($coach);

        $this->actingAs($coach)->get(route('training.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 5',
                'Content coming in a later phase',
                'href="'.route('profile.show').'"',
                'Back',
            ], false)
            ->assertDontSee('Gate status:')
            ->assertDontSee('WEEKLY REQUIREMENT')
            ->assertDontSee('week-main-action')
            ->assertDontSee('Choose Weekly Video');
    }

    public function test_week_page_query_count_does_not_grow_with_activities_and_criteria(): void
    {
        $this->assertSame($this->weekPageQueryCount(1), $this->weekPageQueryCount(3));
    }

    /**
     * Give a new participant a Week 2 with the given number of activities and required criteria (each evaluated) and
     * a chosen video, and count the queries of the participant's page request after a warm-up request.
     */
    private function weekPageQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        ['program' => $program, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->weekFixture(rows: $rows, participant: $participant);
        $this->chooseWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        foreach ($criteria as $criterion) {
            GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criterion->id, 'status' => 'met']);
        }
        $url = route('training.weeks.show', [$program, $week]);

        $this->actingAs($participant)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($participant)->get($url)->assertOk();
        DB::disableQueryLog();
        $this->assertSame($rows, substr_count($response->getContent(), 'View activity →'));

        return count(DB::getQueryLog());
    }

    /**
     * A Week 2 with one Gate (the given number of required criteria, the first of them the weekly video item, plus one
     * optional criterion) and the given number of active activities, in a curriculum that the participant, when
     * given, is in.
     *
     * @return array{program: Program, week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, activities: Collection<int, Activity>}
     */
    private function weekFixture(int $rows, ?User $participant = null): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $program = $week->program;
        if ($participant) {
            $program->participants()->attach($participant, ['joined_at' => now()]);
        }
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criteria = collect(range(1, $rows))
            ->map(fn (int $position): GateCriterion => GateCriterion::factory()->for($gate, 'gate')->create([
                'criterion_type' => $position === 1 ? 'weekly_video' : 'activity_completion',
                'position' => $position,
            ]));
        GateCriterion::factory()->for($gate, 'gate')->create(['title' => 'Optional stretch goal', 'is_required' => false, 'position' => 10]);
        $activities = collect(range(1, $rows))
            ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]));

        return ['program' => $program, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities];
    }

    private function chooseWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();
    }
}
