<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateCriterion;
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

class CoachGateReviewPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ready_participant_shows_summary_criteria_and_a_single_main_action_for_the_decision_form(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture(activityCount: 2, extraCriteriaCount: 1);
        foreach ($activities as $activity) {
            $this->completeActivity($participant, $activity);
        }
        foreach (range(1, 3) as $day) {
            DailyLog::factory()->for($participant, 'participant')->for($activities->first())->create(['logged_on' => now()->subDays($day)->toDateString()]);
        }
        foreach (range(4, 5) as $day) {
            DailyLog::factory()->for($participant, 'participant')->for($activities->last())->create(['logged_on' => now()->subDays($day)->toDateString()]);
        }
        $recording = $this->uploadWeeklyVideo($participant, $week);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision', 'comments' => 'Strong week, finish the video.']);
        [$activityCriterion, $videoCriterion] = $criteria;

        $response = $this->actingAs($coach)->get(route('training.coach.gates.show', [$week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2 · GATE REVIEW',
                e($gate->title),
                'Participant: '.e($participant->name),
                'id="gate-review-main-action"',
                'form="gate-review-form"',
                'Save Gate Evaluation',
                'Gate readiness',
                '<dt class="text-sm text-muted">Criteria</dt>',
                '2 / 2',
                'Daily logs',
                '5 / 5',
                'Activities',
                '2 / 2 submitted',
                'Video Recording',
                'Uploaded',
                'Gate criteria',
                e($activityCriterion->title),
                'REQUIRED WEEKLY VIDEO RECORDING',
                'href="'.route('training.video-recording.view', [$week, $recording]).'"',
                'View Video',
                'action="'.route('training.coach.gates.update', [$week, $participant, $gate]).'" id="gate-review-form"',
                'name="status"',
                'Criterion decisions',
                'name="criteria['.$activityCriterion->id.']"',
                'value="met"', '>Met</option>',
                'value="not_met" selected>Not met</option>',
                '>Required Weekly Video Recording</label>',
                'name="criteria['.$videoCriterion->id.']"',
                'name="comments"',
                'Strong week, finish the video.',
            ], false)
            ->assertDontSee('Blockers')
            ->assertDontSee('Optional stretch goal');

        $this->assertSame(1, substr_count($response->getContent(), 'Save Gate Evaluation'));
    }

    public function test_blocked_participant_shows_the_blockers_and_a_missing_video(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'Daily logs',
                '0 / 5',
                'Activities',
                '0 / 1 submitted',
                'Video Recording',
                'Missing',
                'Blockers',
                'Daily logs: 0 of 5 completed. Complete 5 more Week 2 daily logs before this Gate can be passed.',
                'Activity 1 must have a submitted version.',
                'Required Weekly Video Recording is missing. Upload the video before this Gate can be passed.',
                'Gate criteria',
            ])
            ->assertDontSee('View Video');
    }

    public function test_a_rejected_decision_returns_to_the_page_with_the_error(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        $pageUrl = route('training.coach.gates.show', [$week, $participant, $gate]);

        $this->actingAs($coach)->from($pageUrl)->followingRedirects()
            ->post(route('training.coach.gates.update', [$week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
                'comments' => 'Passing early.',
            ])
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('The Week 2 Gate cannot pass until every required criterion and objective requirement is satisfied.')
            ->assertSee('Passing early.');
    }

    public function test_saving_a_review_stores_each_required_criterion_decision_and_a_left_out_criterion_as_not_met(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 2);
        [$metCriterion, $notMetCriterion, $videoCriterion] = $criteria;

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$week, $participant, $gate]), [
                'status' => 'needs_revision',
                'criteria' => [$metCriterion->id => 'met', $notMetCriterion->id => 'not_met'],
            ])
            ->assertSessionHasNoErrors();

        $criterionEvaluations = GateEvaluation::query()->where('user_id', $participant->id)->sole()->criterionEvaluations->keyBy('gate_criterion_id');
        $this->assertCount(3, $criterionEvaluations);
        $this->assertSame(GateCriterionStatus::Met, $criterionEvaluations->get($metCriterion->id)->status);
        $this->assertSame(GateCriterionStatus::NotMet, $criterionEvaluations->get($notMetCriterion->id)->status);
        $this->assertSame(GateCriterionStatus::NotMet, $criterionEvaluations->get($videoCriterion->id)->status);
    }

    public function test_a_ready_gate_cannot_pass_while_a_required_criterion_is_not_met(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        [$activityCriterion, $videoCriterion] = $criteria;
        $this->completeActivity($participant, $activities->first());
        foreach (range(1, 5) as $day) {
            DailyLog::factory()->for($participant, 'participant')->for($activities->first())->create(['logged_on' => now()->subDays($day)->toDateString()]);
        }
        $this->uploadWeeklyVideo($participant, $week);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => [$activityCriterion->id => 'not_met', $videoCriterion->id => 'met'],
            ])
            ->assertSessionHasErrors(['gate' => 'The Week 2 Gate cannot pass until every required criterion and objective requirement is satisfied.']);
        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => [$activityCriterion->id => 'met', $videoCriterion->id => 'met'],
            ])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('gate_evaluations', ['user_id' => $participant->id, 'status' => 'passed']);
    }

    public function test_a_criterion_decision_must_be_met_or_not_met(): void
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture(activityCount: 1, extraCriteriaCount: 1);
        $criterionId = $criteria->first()->id;

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$week, $participant, $gate]), [
                'status' => 'needs_revision',
                'criteria' => [$criterionId => 'passed'],
            ])
            ->assertSessionHasErrors(['criteria.'.$criterionId => "The selected criteria.{$criterionId} is invalid."]);

        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);
    }

    public function test_gate_review_query_count_does_not_grow_with_activities_daily_logs_and_criteria(): void
    {
        $this->assertSame($this->gateReviewQueryCount(1), $this->gateReviewQueryCount(3));
    }

    /**
     * Give a new participant a Week 2 Gate with the given number of activities (each submitted with a daily log) and
     * extra required criteria, and count the queries of the assigned coach's page request after a warm-up request.
     */
    private function gateReviewQueryCount(int $rows): int
    {
        [$coach, $participant] = $this->assignedCoachAndParticipant();
        ['week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture(activityCount: $rows, extraCriteriaCount: $rows);
        foreach ($activities as $index => $activity) {
            $this->completeActivity($participant, $activity);
            DailyLog::factory()->for($participant, 'participant')->for($activity)->create(['logged_on' => now()->subDays($index + 1)->toDateString()]);
        }
        $this->uploadWeeklyVideo($participant, $week);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        $url = route('training.coach.gates.show', [$week, $participant, $gate]);

        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee("{$rows} / {$rows} submitted");
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A Week 2 Gate with a required weekly video criterion, the given number of other required criteria, one optional
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

    private function completeActivity(User $participant, Activity $activity): void
    {
        ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
    }

    private function uploadWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'path' => "videos/{$participant->id}.mp4", 'original_filename' => 'week-2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1048576, 'uploaded_at' => now()]);
    }

    /**
     * A participant and a coach assigned to them.
     *
     * @return array{User, User}
     */
    private function assignedCoachAndParticipant(): array
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        return [$coach, $participant];
    }
}
