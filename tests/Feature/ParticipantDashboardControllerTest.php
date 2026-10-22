<?php

namespace Tests\Feature;

use App\Enums\ActivityScoreStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParticipantDashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_query_count_does_not_grow_with_weeks_activities_and_gates(): void
    {
        $singleRowQueryCount = $this->dashboardQueryCount(1);

        Program::query()->update(['is_active' => false]);

        $threeRowQueryCount = $this->dashboardQueryCount(3);

        $this->assertSame($singleRowQueryCount, $threeRowQueryCount);
    }

    public function test_dashboard_shows_the_published_score_of_an_activity_but_not_a_draft_score(): void
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $coach = User::factory()->create(['role' => 'coach']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);

        foreach ([[1, ActivityScoreStatus::Published, 88], [2, ActivityScoreStatus::Draft, 41]] as [$position, $status, $totalScore]) {
            $activity = Activity::factory()->for($week, 'week')->create(['position' => $position]);
            $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
            $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
            ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => $coach->id, 'status' => $status, 'total_score' => $totalScore, 'performance_band' => 'Proficient']);
        }

        $html = $this->actingAs($participant)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Score: <strong'));
        $this->assertStringContainsString('>88 / 100</strong>', $html);
        $this->assertStringNotContainsString('41 / 100', $html);
    }

    public function test_gate_card_shows_the_saved_evaluation_status_or_the_readiness_status(): void
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $evaluatedGate = TrainingGate::factory()->for($week, 'week')->create(['title' => 'Evaluated Gate', 'position' => 1]);
        TrainingGate::factory()->for($week, 'week')->create(['title' => 'Unevaluated Gate', 'position' => 2]);
        GateEvaluation::factory()->for($evaluatedGate, 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Week 2 Gate · Evaluated Gate', 'Status: <strong>Needs revision</strong>',
                'Week 2 Gate · Unevaluated Gate', 'Status: <strong>Not ready</strong>',
            ], false);
    }

    /**
     * Build an active program with the given number of weeks, activities per week and gates, fill it with
     * participant progress, and count the queries of a dashboard request after a warm-up request.
     *
     * Weeks are numbered up to Week 4, so every week falls inside the Weeks 2–4 that ParticipantNotificationService::sync()
     * covers (the sync runs when progress is written, never on a dashboard request). Activities are numbered from 2 so
     * both measurements include the one-off Week 4 Activity 2 interview partner lookup.
     */
    private function dashboardQueryCount(int $rows): int
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $program = Program::factory()->create();

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position + 4 - $rows, 'position' => $position]);
            $gate = TrainingGate::factory()->for($week, 'week')->create();
            GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $position < $rows ? 'passed' : 'not_ready']);
            WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'disk' => 'local', 'path' => "weekly-video/week-{$position}.mp4", 'original_filename' => "week-{$position}.mp4", 'mime_type' => 'video/mp4', 'size_bytes' => 100, 'uploaded_at' => now()]);

            foreach (range(2, $rows + 1) as $activityPosition) {
                $activity = Activity::factory()->for($week, 'week')->create(['position' => $activityPosition]);
                $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create())->create();
                $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
                $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
                SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
                DailyLog::factory()->for($participant, 'participant')->for($activity)->create();
            }
        }

        $this->actingAs($participant)->get(route('dashboard'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('dashboard'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
