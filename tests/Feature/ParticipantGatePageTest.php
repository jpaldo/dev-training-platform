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
use App\Models\TrainingSession;
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

    public function test_not_ready_participant_sees_the_readiness_summary_and_a_single_choose_main_action(): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);
        [$activityCriterion] = $criteria;

        $response = $this->actingAs($participant)->get(route('training.gates.show', [$session, $week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2 · GATE',
                e($gate->title),
                e($gate->description),
                'id="gate-main-action"',
                'href="'.route('training.video-recording.edit', [$session, $week]).'"',
                'Choose Weekly Video →',
                'Gate readiness',
                'NOT READY',
                'Activity 1',
                'Not submitted',
                'Activity 1 must have a submitted version.',
                'Weekly Video Recording',
                'Missing',
                'Choose the required weekly video recording from Google Drive before this week can be marked completed.',
                'id="gate-video-title"',
                'Status:',
                'MISSING',
                'Choose a video recording of your work for this week from your Google Drive.',
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

        $this->assertSame(1, substr_count($response->getContent(), 'Choose Weekly Video'));
    }

    public function test_readiness_summary_lists_each_outstanding_reason_once(): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 2, extraCriteriaCount: 1);
        [$firstActivity] = $activities;
        EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($firstActivity), 'activityRequirement')->create();
        ActivitySubmission::factory()->for($participant, 'participant')->for($firstActivity)->create(['status' => 'submitted']);

        $response = $this->actingAs($participant)->get(route('training.gates.show', [$session, $week, $gate]))
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
                'Choose the required weekly video recording from Google Drive before this week can be marked completed.',
                'id="gate-video-title"',
            ], false)
            ->assertDontSee('Complete the outstanding activity and evidence requirements');

        $this->assertSame(1, substr_count($response->getContent(), 'Choose the required weekly video recording from Google Drive before this week can be marked completed.'));
    }

    public function test_ready_participant_without_an_evaluation_sees_the_ready_notice_and_no_main_action(): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);
        $this->completeWeek($participant, $activities->first());
        $recording = $this->chooseWeeklyVideo($participant, $week);

        $this->actingAs($participant)->get(route('training.gates.show', [$session, $week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'READY FOR REVIEW',
                'Your Gate is ready for Mentor review. A Mentor/Admin must record the official result.',
                'Status:',
                'AWAITING REVIEW',
                'href="'.$recording->driveViewUrl().'" target="_blank" rel="noopener noreferrer"',
                '>View Video ↗<span class="sr-only"> (opens Google Drive in a new tab)</span></a>',
            ], false)
            ->assertDontSee('gate-main-action')
            ->assertDontSee('Choose Weekly Video');
    }

    public function test_a_gate_without_a_weekly_video_criterion_is_ready_without_a_video_and_shows_no_video_section(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create();
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->completeWeek($participant, Activity::factory()->for($week, 'week')->create(['position' => 1]));

        $this->actingAs($participant)->get(route('training.gates.show', [$session, $week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'READY FOR REVIEW',
                'Your Gate is ready for Mentor review. A Mentor/Admin must record the official result.',
                'Weekly checklist',
            ])
            ->assertDontSee('gate-main-action')
            ->assertDontSee('Choose Weekly Video')
            ->assertDontSee('gate-video-title')
            ->assertDontSee('Weekly Video Recording');
    }

    public function test_evaluated_gate_shows_the_decision_the_ticked_checklist_items_and_coach_feedback(): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);
        [$activityCriterion, $videoCriterion] = $criteria;
        $this->completeWeek($participant, $activities->first());
        $this->chooseWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision', 'comments' => 'Re-record the walkthrough.']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $activityCriterion->id, 'status' => 'not_met']);
        GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $videoCriterion->id, 'status' => 'met']);

        $response = $this->actingAs($participant)->get(route('training.gates.show', [$session, $week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'INCOMPLETE',
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

    public function test_work_and_the_review_in_another_session_do_not_show(): void
    {
        $participant = User::factory()->create();
        ['session' => $sessionA, 'week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);
        $sessionB = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->completeWeek($participant, $activities->first());
        $this->chooseWeeklyVideo($participant, $week);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed', 'comments' => 'Great work in session A.']);

        $this->actingAs($participant)->get(route('training.gates.show', [$sessionA, $week, $gate]))
            ->assertOk()
            ->assertSee('COMPLETED')
            ->assertSee('Great work in session A.');

        $this->actingAs($participant)->get(route('training.gates.show', [$sessionB, $week, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('training.video-recording.edit', [$sessionB, $week]).'"',
                'Choose Weekly Video →',
                'NOT READY',
                'Activity 1 must have a submitted version.',
            ], false)
            ->assertDontSee('COMPLETED')
            ->assertDontSee('Great work in session A.')
            ->assertDontSee('View Video');
    }

    public function test_a_participant_who_is_not_in_the_session_cannot_open_its_gate_page(): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);

        $this->actingAs(User::factory()->create())->get(route('training.gates.show', [$session, $week, $gate]))->assertForbidden();
        $this->actingAs($participant)->get("/training/weeks/{$week->id}/gates/{$gate->id}")->assertNotFound();
    }

    #[DataProvider('savedGateStatuses')]
    public function test_gate_status_badge_shows_the_saved_evaluation_status_with_its_tone(GateEvaluationStatus $status, string $badge): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($participant)->get(route('training.gates.show', [$session, $week, $gate]))
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
            'needs revision' => [GateEvaluationStatus::NeedsRevision, 'border-amber-500 text-amber-200">INCOMPLETE</span>'],
            'failed' => [GateEvaluationStatus::Failed, 'border-danger text-rose-200">FAILED</span>'],
            'passed' => [GateEvaluationStatus::Passed, 'border-emerald-400 text-emerald-200">COMPLETED</span>'],
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
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture($participant, activityCount: $rows, extraCriteriaCount: $rows);
        foreach ($activities as $activity) {
            ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        }
        $this->chooseWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision', 'comments' => 'Keep going.']);
        foreach ($criteria as $criterion) {
            GateCriterionEvaluation::factory()->create(['gate_evaluation_id' => $evaluation->id, 'gate_criterion_id' => $criterion->id]);
        }
        $url = route('training.gates.show', [$session, $week, $gate]);

        $this->actingAs($participant)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get($url)->assertOk()->assertSee('Keep going.');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A Week 2 Gate with the given number of other required criteria, a required weekly video criterion, one optional
     * criterion and the given number of active activities, and an active session of the week's program with the
     * participant in it.
     *
     * @return array{session: TrainingSession, week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, activities: Collection<int, Activity>}
     */
    private function gateFixture(User $participant, int $activityCount, int $extraCriteriaCount): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criteria = collect(range(1, $extraCriteriaCount))
            ->map(fn (int $position): GateCriterion => GateCriterion::factory()->for($gate, 'gate')->create(['position' => $position]))
            ->push(GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video', 'title' => 'Required Weekly Video Recording', 'position' => 10]));
        GateCriterion::factory()->for($gate, 'gate')->create(['title' => 'Optional stretch goal', 'is_required' => false, 'position' => 11]);
        $activities = collect(range(1, $activityCount))
            ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]));

        return ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities];
    }

    /**
     * Submit the week's only activity.
     */
    private function completeWeek(User $participant, Activity $activity): void
    {
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
    }

    private function chooseWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();
    }
}
