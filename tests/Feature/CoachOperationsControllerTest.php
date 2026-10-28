<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
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

    public function test_dashboard_counts_only_memberships_that_passed_every_active_week_of_their_curriculum(): void
    {
        $program = Program::factory()->create();
        $weekTwoGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]), 'week')->create();
        $weekThreeGate = TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 2]), 'week')->create();
        TrainingGate::factory()->for(TrainingWeek::factory()->for($program)->create(['week_number' => 4, 'position' => 3, 'is_active' => false]), 'week')->create();
        $otherGate = TrainingGate::factory()->for(TrainingWeek::factory()->create(['week_number' => 2]), 'week')->create();
        $completed = User::factory()->create();
        $partlyCompleted = User::factory()->create();
        $inTwoCurricula = User::factory()->create();
        $program->participants()->attach([$completed->id, $partlyCompleted->id, $inTwoCurricula->id], ['joined_at' => now()]);
        $otherGate->week->program->participants()->attach($inTwoCurricula, ['joined_at' => now()]);
        GateEvaluation::factory()->for($otherGate, 'gate')->for($inTwoCurricula, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekTwoGate, 'gate')->for($inTwoCurricula, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekTwoGate, 'gate')->for($completed, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekThreeGate, 'gate')->for($completed, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekTwoGate, 'gate')->for($partlyCompleted, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($weekThreeGate, 'gate')->for($partlyCompleted, 'participant')->create(['status' => 'needs_revision']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['participants']['value'] === 4 && $stats['completed']['value'] === 2);
    }

    public function test_dashboard_counts_at_risk_memberships_without_loading_their_progress(): void
    {
        $activity = Activity::factory()->create();
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
        [$revising, $failing, $failedGate, $steady] = User::factory()->count(4)->create()->all();
        $activity->week->program->participants()->attach(collect([$revising, $failing, $failedGate, $steady])->mapWithKeys(fn (User $participant): array => [$participant->id => ['joined_at' => now()]])->all());
        ActivitySubmission::factory()->for($activity)->for($revising, 'participant')->create(['status' => 'needs_revision']);
        ActivitySubmission::factory()->for($activity)->for($failing, 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for($gate, 'gate')->for($failedGate, 'participant')->create(['status' => 'failed']);
        ActivitySubmission::factory()->for($activity)->for($steady, 'participant')->create(['status' => 'approved']);
        GateEvaluation::factory()->for($gate, 'gate')->for($steady, 'participant')->create(['status' => 'passed']);

        DB::enableQueryLog();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['participants']['value'] === 4 && $stats['at_risk']['value'] === 3);
        DB::disableQueryLog();

        $progressQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => preg_match('/from "(attendance_sessions|google_drive_connections|submission_versions|mentor_assignments)"/', $query) === 1);
        $this->assertSame([], $progressQueries->values()->all());
    }

    public function test_participant_detail_query_count_does_not_grow_with_gates(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $singleRowQueryCount = $this->queryCount($coach, $this->mentoredParticipantUrl($coach, $this->createGateProgress(1)->first()));

        Program::query()->update(['is_active' => false]);

        $threeRowQueryCount = $this->queryCount($coach, $this->mentoredParticipantUrl($coach, $this->createGateProgress(3)->first()));

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    public function test_gates_query_count_does_not_grow_with_gates_and_participants(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $this->mentorInCurriculaOfTheirGates($coach, $this->createGateProgress(1));
        $singleRowQueryCount = $this->queryCount($coach, route('coach.gates'));

        $this->mentorInCurriculaOfTheirGates($coach, $this->createGateProgress(3));
        $threeRowQueryCount = $this->queryCount($coach, route('coach.gates'), expectedRows: 1 + 3 * 3);

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    /**
     * Create an active program with the given number of Week 2+ Gates and participants. Every participant has
     * a submitted activity with evidence, a Weekly Video Recording and a Gate evaluation per week, and every Gate
     * has a weekly video criterion, so the video lookup runs for every row.
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
            $videoCriterion = GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
            $activity = Activity::factory()->for($week, 'week')->create();
            $deliverable = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create())->create();

            foreach ($participants as $participant) {
                $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
                $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
                SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($deliverable, 'evidenceRequirement')->create();
                WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();
                $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
                GateCriterionEvaluation::factory()->for($evaluation, 'gateEvaluation')->for($videoCriterion, 'criterion')->create(['status' => 'met']);
            }
        }

        return $participants;
    }

    /**
     * Add the participant to the curriculum of their training records, make the coach their current mentor there and
     * return the coach participant page of that curriculum.
     */
    private function mentoredParticipantUrl(User $coach, User $participant): string
    {
        $program = $this->curriculaOfGateEvaluations($participant)->sole();
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $program->mentors()->attach($coach);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        return route('coach.participants.show', [$program, $participant]);
    }

    /**
     * Add each participant to the curricula of their Gate evaluations and make the coach a mentor of those curricula and
     * the participant's current mentor there, so the coach's Gate board lists their rows.
     *
     * @param  Collection<int, User>  $participants
     */
    private function mentorInCurriculaOfTheirGates(User $coach, Collection $participants): void
    {
        foreach ($participants as $participant) {
            foreach ($this->curriculaOfGateEvaluations($participant) as $program) {
                $program->participants()->attach($participant, ['joined_at' => now()]);
                $program->mentors()->syncWithoutDetaching([$coach->id]);
                MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
            }
        }
    }

    /**
     * The curricula whose weeks hold the participant's Gate evaluations.
     *
     * @return Collection<int, Program>
     */
    private function curriculaOfGateEvaluations(User $participant): Collection
    {
        return Program::query()
            ->whereHas('weeks.gates.evaluations', fn (Builder $evaluations): Builder => $evaluations->where('user_id', $participant->id))
            ->get();
    }

    /**
     * Count the queries of a coach request to the URL after a warm-up request, which lists the expected number of Gate
     * board rows when given.
     */
    private function queryCount(User $coach, string $url, ?int $expectedRows = null): int
    {
        $response = $this->actingAs($coach)->get($url)->assertOk();
        if ($expectedRows !== null) {
            $this->assertSame($expectedRows, substr_count($response->getContent(), 'data-gate-row'));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
