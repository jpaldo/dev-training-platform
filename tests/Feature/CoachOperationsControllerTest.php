<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachOperationsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_query_count_does_not_grow_with_participants(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $this->createGateProgress(1);
        $singleRowQueryCount = $this->queryCount($coach, route('coach.dashboard'));

        Program::query()->update(['is_active' => false]);

        $this->createGateProgress(3);
        $threeRowQueryCount = $this->queryCount($coach, route('coach.dashboard'));

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    public function test_dashboard_counts_only_participants_who_passed_every_gate_of_the_active_weeks(): void
    {
        $program = Program::factory()->create();
        $weekTwoGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]), 'week')->create();
        $weekThreeGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]), 'week')->create();
        TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 4, 'position' => 3, 'is_active' => false]), 'week')->create();
        $completed = User::factory()->create();
        $partlyCompleted = User::factory()->create();
        GateEvaluation::factory()->for($weekTwoGate, 'gate')->for($completed, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekThreeGate, 'gate')->for($completed, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekTwoGate, 'gate')->for($partlyCompleted, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekThreeGate, 'gate')->for($partlyCompleted, 'participant')->create(['status' => 'needs_revision']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['participants']['value'] === 2 && $stats['completed']['value'] === 1);
    }

    public function test_dashboard_counts_at_risk_participants_without_loading_their_progress(): void
    {
        ActivitySubmission::factory()->for(User::factory(), 'participant')->create(['status' => 'needs_revision']);
        ActivitySubmission::factory()->for(User::factory(), 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for(User::factory(), 'participant')->create(['status' => 'failed']);
        $steady = User::factory()->create();
        ActivitySubmission::factory()->for($steady, 'participant')->create(['status' => 'approved']);
        GateEvaluation::factory()->for($steady, 'participant')->create(['status' => 'passed']);

        DB::enableQueryLog();
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['participants']['value'] === 4 && $stats['at_risk']['value'] === 3);
        DB::disableQueryLog();

        $progressQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => preg_match('/from "(attendance_sessions|google_drive_connections|submission_versions|coach_participant_assignments)"/', $query) === 1);
        $this->assertSame([], $progressQueries->values()->all());
    }

    public function test_participant_detail_query_count_does_not_grow_with_gates(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $singleRowQueryCount = $this->queryCount($coach, route('coach.participants.show', $this->createGateProgress(1)->first()));
        $threeRowQueryCount = $this->queryCount($coach, route('coach.participants.show', $this->createGateProgress(3)->first()));

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    public function test_gates_query_count_does_not_grow_with_gates_and_participants(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $this->createGateProgress(1);
        $singleRowQueryCount = $this->queryCount($coach, route('coach.gates'));

        $this->createGateProgress(3);
        $threeRowQueryCount = $this->queryCount($coach, route('coach.gates'));

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    /**
     * Create an active program with the given number of Week 2+ Gates and participants. Every participant has
     * a submitted Activity 3 with evidence, a daily log and a Gate evaluation per week; every Gate accepts a
     * legacy Weekly Loom link and has no Weekly Video Recording, so the legacy lookup runs for every row.
     *
     * @return Collection<int, User>
     */
    private function createGateProgress(int $rows): Collection
    {
        $program = Program::factory()->create();
        $participants = User::factory()->count($rows)->create();

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position + 1, 'position' => $position]);
            $gate = TrainingGate::factory()->for($week, 'week')->create();
            $loomCriterion = GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_loom']);
            $activity = Activity::factory()->for($week, 'week')->create(['position' => 3]);
            $deliverable = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create())->create();
            $loomLink = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'gate_evidence']))->create(['evidence_type' => 'url']);

            foreach ($participants as $participant) {
                $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
                $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
                SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($deliverable, 'evidenceRequirement')->create();
                SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($loomLink, 'evidenceRequirement')->create(['evidence_type' => 'url', 'url' => "https://www.loom.com/share/{$participant->id}-{$week->id}"]);
                DailyLog::factory()->for($participant, 'participant')->for($activity)->create();
                $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
                GateCriterionEvaluation::factory()->for($evaluation, 'gateEvaluation')->for($loomCriterion, 'criterion')->create(['status' => 'met']);
            }
        }

        return $participants;
    }

    /**
     * Count the queries of a coach request to the URL after a warm-up request.
     */
    private function queryCount(User $coach, string $url): int
    {
        $this->actingAs($coach)->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
