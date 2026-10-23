<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantGatePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_not_ready_participant_sees_the_readiness_summary_and_a_single_upload_main_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        [$activityCriterion] = $criteria;

        $response = $this->actingAs($participant)->get(route('training.gates.show', [$week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2 · GATE',
                e($gate->title),
                e($gate->description),
                'id="gate-main-action"',
                'href="'.route('training.video-recording.edit', $week).'"',
                'Upload Video Recording →',
                'Gate readiness',
                'NOT READY',
                'Activity 1',
                'Not submitted',
                'Activity 1 must have a submitted version.',
                'Weekly Video Recording',
                'Missing',
                'Upload the required weekly video recording before this Gate can be passed.',
                'id="gate-video-title"',
                'Status:',
                'MISSING',
                'Upload a video recording of your work for this week.',
                'Weekly checklist',
                'Your mentor checks each item by hand and ticks it.',
                e($activityCriterion->title),
                'Awaiting Review',
                e($activityCriterion->description),
                'Required Weekly Video Recording',
                'Awaiting Review',
            ], false)
            ->assertDontSee('✓')
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('Optional stretch goal')
            ->assertDontSee('Daily logs')
            ->assertDontSee('View Video')
            ->assertDontSee('Your Gate is ready for Mentor review')
            ->assertDontSee('Mentor feedback');

        $this->assertSame(1, substr_count($response->getContent(), 'Upload Video Recording'));
    }

    public function test_readiness_summary_lists_each_outstanding_reason_once(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture(activityCount: 2, extraCriteriaCount: 1);
        [$firstActivity] = $activities;
        EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($firstActivity), 'activityRequirement')->create();
        ActivitySubmission::factory()->for($participant, 'participant')->for($firstActivity)->create(['status' => 'submitted']);

        $response = $this->actingAs($participant)->get(route('training.gates.show', [$week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'Gate readiness',
                '<strong>Activity 1 evidence</strong>',
                'Missing',
                'Required evidence for Activity 1 is missing.',
                '<strong>Activity 2</strong>',
                'Not submitted',
                'Activity 2 must have a submitted version.',
                '<strong>Weekly Video Recording</strong>',
                'Upload the required weekly video recording before this Gate can be passed.',
                'id="gate-video-title"',
            ], false)
            ->assertDontSee('Complete the outstanding activity and evidence requirements');

        $this->assertSame(1, substr_count($response->getContent(), 'Upload the required weekly video recording before this Gate can be passed.'));
    }

    public function test_ready_participant_without_an_evaluation_sees_the_ready_notice_and_no_main_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        $this->completeWeek($participant, $activities->first());
        $recording = $this->uploadWeeklyVideo($participant, $week);

        $this->actingAs($participant)->get(route('training.gates.show', [$week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'READY FOR REVIEW',
                'Your Gate is ready for Mentor review. A Mentor/Admin must record the official result.',
                'Status:',
                'AWAITING REVIEW',
                'href="'.route('training.video-recording.view', [$week, $recording]).'"',
                'View Video ↗',
            ], false)
            ->assertDontSee('gate-main-action')
            ->assertDontSee('Upload Video Recording');
    }

    public function test_a_gate_without_a_weekly_video_criterion_is_ready_without_a_video_and_shows_no_video_section(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create();
        $this->completeWeek($participant, Activity::factory()->for($week, 'week')->create(['position' => 1]));

        $this->actingAs($participant)->get(route('training.gates.show', [$week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'READY FOR REVIEW',
                'Your Gate is ready for Mentor review. A Mentor/Admin must record the official result.',
                'Weekly checklist',
            ])
            ->assertDontSee('gate-main-action')
            ->assertDontSee('Upload Video Recording')
            ->assertDontSee('gate-video-title')
            ->assertDontSee('Weekly Video Recording');
    }

    public function test_evaluated_gate_shows_the_decision_the_ticked_checklist_items_and_coach_feedback(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        [$activityCriterion, $videoCriterion] = $criteria;
        $this->completeWeek($participant, $activities->first());
        $this->uploadWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision', 'comments' => 'Re-record the walkthrough.']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $activityCriterion->id, 'status' => 'not_met']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $videoCriterion->id, 'status' => 'met']);

        $response = $this->actingAs($participant)->get(route('training.gates.show', [$week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'NEEDS REVISION',
                'Status:',
                'MET',
                'Weekly checklist',
                'font-black border-line"',
                e($activityCriterion->title),
                'Missing',
                'font-black border-emerald-400 bg-emerald-400 text-canvas"',
                '>✓</span>',
                'Required Weekly Video Recording',
                'Met',
                'Mentor feedback',
                'Re-record the walkthrough.',
            ], false)
            ->assertDontSee('Your Gate is ready for Mentor review')
            ->assertDontSee('type="checkbox"', false);

        $this->assertSame(1, substr_count($response->getContent(), '✓'));
    }

    #[DataProvider('savedGateStatuses')]
    public function test_gate_status_badge_shows_the_saved_evaluation_status_with_its_tone(GateEvaluationStatus $status, string $badge): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($participant)->get(route('training.gates.show', [$week, $gate]))
            ->assertOk()
            ->assertSee($badge, false);
    }

    /**
     * Each status a Gate evaluation can be saved with and its badge on the participant Gate page.
     *
     * @return array<string, array{GateEvaluationStatus, string}>
     */
    public static function savedGateStatuses(): array
    {
        return [
            'not ready' => [GateEvaluationStatus::NotReady, 'border-line text-muted">NOT READY</span>'],
            'needs revision' => [GateEvaluationStatus::NeedsRevision, 'border-amber-500 text-amber-200">NEEDS REVISION</span>'],
            'failed' => [GateEvaluationStatus::Failed, 'border-danger text-rose-200">FAILED</span>'],
            'passed' => [GateEvaluationStatus::Passed, 'border-emerald-400 text-emerald-200">PASSED</span>'],
        ];
    }

    public function test_gate_page_query_count_does_not_grow_with_activities_and_criteria(): void
    {
        $this->assertSame($this->gatePageQueryCount(1), $this->gatePageQueryCount(3));
    }

    /**
     * Give a new participant a Week 2 Gate with the given number of activities (each submitted), extra required
     * criteria and criterion evaluations, and count the queries of the participant's page request
     * after a warm-up request.
     */
    private function gatePageQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture(activityCount: $rows, extraCriteriaCount: $rows);
        foreach ($activities as $activity) {
            ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        }
        $this->uploadWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision', 'comments' => 'Keep going.']);
        foreach ($criteria as $criterion) {
            GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criterion->id]);
        }
        $url = route('training.gates.show', [$week, $gate]);

        $this->actingAs($participant)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get($url)->assertOk()->assertSee('Keep going.');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A Week 2 Gate with the given number of other required criteria, a required weekly video criterion, one optional
     * criterion and the given number of active activities.
     *
     * @return array{week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, activities: Collection<int, Activity>}
     */
    private function gateFixture(int $activityCount, int $extraCriteriaCount): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criteria = collect(range(1, $extraCriteriaCount))
            ->map(fn (int $position): GateCriterion => GateCriterion::factory()->for($gate, 'gate')->create(['position' => $position]))
            ->push(GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video', 'title' => 'Required Weekly Video Recording', 'position' => 10]));
        GateCriterion::factory()->for($gate, 'gate')->create(['title' => 'Optional stretch goal', 'is_required' => false, 'position' => 11]);
        $activities = collect(range(1, $activityCount))
            ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]));

        return ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities];
    }

    /**
     * Submit the week's only activity.
     */
    private function completeWeek(User $participant, Activity $activity): void
    {
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
    }

    private function uploadWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'path' => "videos/{$participant->id}.mp4", 'original_filename' => 'week-2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1048576, 'uploaded_at' => now()]);
    }
}
