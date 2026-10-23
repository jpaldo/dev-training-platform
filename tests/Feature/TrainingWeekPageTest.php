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

    public function test_participant_without_a_video_sees_the_gate_activities_and_a_single_upload_main_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->weekFixture(rows: 2);
        Activity::factory()->for($week, 'week')->create(['title' => 'Retired activity', 'is_active' => false, 'position' => 3]);
        [$firstActivity, $secondActivity] = $activities;

        $response = $this->actingAs($participant)->get(route('training.weeks.show', $week))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2',
                e($week->title),
                e($week->description),
                'id="week-main-action"',
                'href="'.route('training.video-recording.edit', $week).'"',
                'Upload Video Recording →',
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
                'href="'.route('training.gates.show', [$week, $gate]).'"',
                'View Gate',
                'ACTIVITY 1',
                e($firstActivity->title),
                'href="'.route('training.activities.show', [$week, $firstActivity]).'"',
                'View activity →',
                'ACTIVITY 2',
                e($secondActivity->title),
                'href="'.route('training.activities.show', [$week, $secondActivity]).'"',
                'View activity →',
                'WEEKLY REQUIREMENT',
                'Video Recording',
                'Status:',
                'Not uploaded',
                'href="'.route('dashboard').'"',
                'Back',
            ], false)
            ->assertDontSee('Optional stretch goal')
            ->assertDontSee('Retired activity')
            ->assertDontSee('✓')
            ->assertDontSee('Replace Video')
            ->assertDontSee('Content coming in a later phase');

        $this->assertSame(1, substr_count($response->getContent(), 'Upload Video Recording'));
    }

    public function test_participant_with_a_video_and_an_evaluated_gate_sees_the_ticked_checklist_items_and_no_main_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->weekFixture(rows: 2);
        $recording = $this->uploadWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criteria[0]->id, 'status' => 'met']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criteria[1]->id, 'status' => 'not_met']);

        $response = $this->actingAs($participant)->get(route('training.weeks.show', $week))
            ->assertOk()
            ->assertSeeInOrder([
                'Gate status:',
                'NEEDS REVISION',
                'font-black border-emerald-400 bg-emerald-400 text-canvas"',
                '>✓</span>',
                e($criteria[0]->title),
                'Met',
                'font-black border-line"',
                e($criteria[1]->title),
                'Missing',
                'Status:',
                'Uploaded',
                route('training.video-recording.view', [$week, $recording]),
                'Replace Video',
            ], false)
            ->assertDontSee('week-main-action')
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('Upload Video Recording');

        $this->assertSame(1, substr_count($response->getContent(), '✓'));
    }

    public function test_ready_participant_without_an_evaluation_sees_the_first_gate_by_position_ready_for_review(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $laterGate, 'activities' => $activities] = $this->weekFixture(rows: 1);
        $laterGate->update(['title' => 'Later Gate', 'position' => 2]);
        $firstGate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'First Gate', 'position' => 1]);
        GateCriterion::factory()->for($firstGate, 'gate')->create(['criterion_type' => 'weekly_video', 'title' => 'Required Weekly Video Recording']);
        ActivitySubmission::factory()->for($participant, 'participant')->for($activities->first())->create(['status' => 'submitted']);
        $this->uploadWeeklyVideo($participant, $week);

        $this->actingAs($participant)->get(route('training.weeks.show', $week))
            ->assertOk()
            ->assertSeeInOrder([
                'First Gate',
                'Gate status:',
                'READY FOR REVIEW',
                'Required Weekly Video Recording',
                'href="'.route('training.gates.show', [$week, $firstGate]).'"',
                'View Gate',
            ], false)
            ->assertDontSee('Later Gate')
            ->assertDontSee('NOT READY');
    }

    public function test_only_a_week_whose_checklist_has_a_video_item_shows_the_video_card_and_upload(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        [$weekOne, $weekTwo, $weekThree] = collect([1, 2, 3])
            ->map(fn (int $number): TrainingWeek => TrainingWeek::factory()->for($program)->create(['week_number' => $number, 'position' => $number]))
            ->all();
        $weekOneGate = TrainingGate::factory()->for($weekOne, 'week')->create();
        GateCriterion::factory()->for($weekOneGate, 'gate')->create(['criterion_type' => 'deliverable']);
        $weekTwoGate = TrainingGate::factory()->for($weekTwo, 'week')->create();
        GateCriterion::factory()->for($weekTwoGate, 'gate')->create(['criterion_type' => 'weekly_video']);
        foreach ([$weekOneGate, $weekTwoGate] as $gate) {
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        }

        $weekOnePage = $this->actingAs($participant)->get(route('training.weeks.show', $weekOne))
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-main-action"',
                'href="'.route('training.gates.show', [$weekOne, $weekOneGate]).'"',
                'View Gate →',
                e($weekOneGate->title),
            ], false)
            ->assertDontSee('WEEKLY REQUIREMENT')
            ->assertDontSee('Upload Video Recording')
            ->assertDontSee(route('training.video-recording.edit', $weekOne), false);
        $this->assertSame(1, substr_count($weekOnePage->getContent(), 'href="'.route('training.gates.show', [$weekOne, $weekOneGate]).'"'));

        $this->actingAs($participant)->get(route('training.weeks.show', $weekTwo))
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-main-action"',
                'href="'.route('training.video-recording.edit', $weekTwo).'"',
                'Upload Video Recording →',
                'WEEKLY REQUIREMENT',
                'Video Recording',
                'Not uploaded',
            ], false);

        $this->actingAs($participant)->get(route('training.weeks.show', $weekThree))
            ->assertOk()
            ->assertDontSee('week-main-action')
            ->assertDontSee('WEEKLY REQUIREMENT')
            ->assertDontSee('Upload Video Recording')
            ->assertDontSee(route('training.video-recording.edit', $weekThree), false);
    }

    public function test_coach_sees_the_gate_not_ready_without_a_view_gate_link(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        ['week' => $week, 'gate' => $gate] = $this->weekFixture(rows: 1);

        $this->actingAs($coach)->get(route('training.weeks.show', $week))
            ->assertOk()
            ->assertSeeInOrder([e($gate->title), 'Gate status:', 'NOT READY', 'Mentor approval is required before the next week unlocks.'], false)
            ->assertDontSee('View Gate')
            ->assertDontSee(route('training.gates.show', [$week, $gate]), false);
    }

    public function test_coach_sees_no_video_card_or_main_action_and_goes_back_to_the_profile(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 5, 'position' => 5]);

        $this->actingAs($coach)->get(route('training.weeks.show', $week))
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
            ->assertDontSee('Upload Video Recording');
    }

    public function test_week_page_query_count_does_not_grow_with_activities_and_criteria(): void
    {
        $this->assertSame($this->weekPageQueryCount(1), $this->weekPageQueryCount(3));
    }

    /**
     * Give a new participant a Week 2 with the given number of activities and required criteria (each evaluated) and
     * an uploaded video, and count the queries of the participant's page request after a warm-up request.
     */
    private function weekPageQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->weekFixture(rows: $rows);
        $this->uploadWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        foreach ($criteria as $criterion) {
            GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criterion->id, 'status' => 'met']);
        }
        $url = route('training.weeks.show', $week);

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
     * optional criterion) and the given number of active activities.
     *
     * @return array{week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, activities: Collection<int, Activity>}
     */
    private function weekFixture(int $rows): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criteria = collect(range(1, $rows))
            ->map(fn (int $position): GateCriterion => GateCriterion::factory()->for($gate, 'gate')->create([
                'criterion_type' => $position === 1 ? 'weekly_video' : 'activity_completion',
                'position' => $position,
            ]));
        GateCriterion::factory()->for($gate, 'gate')->create(['title' => 'Optional stretch goal', 'is_required' => false, 'position' => 10]);
        $activities = collect(range(1, $rows))
            ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]));

        return ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities];
    }

    private function uploadWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'path' => "videos/{$participant->id}.mp4", 'original_filename' => 'week-2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1048576, 'uploaded_at' => now()]);
    }
}
