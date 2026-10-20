<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
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

    /**
     * Build an active program with the given number of weeks, activities per week and gates, fill it with
     * participant progress, and count the queries of a dashboard request after a warm-up request.
     *
     * Weeks are numbered from 5 so that ParticipantNotificationService::sync(), which only covers Weeks 2–4
     * and is tracked as a separate hotspot, does not add per-row queries to this measurement.
     */
    private function dashboardQueryCount(int $rows): int
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $program = Program::factory()->create();

        foreach (range(1, $rows) as $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $position + 4, 'position' => $position]);
            $gate = TrainingGate::factory()->for($week, 'week')->create();
            GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $position < $rows ? 'passed' : 'not_ready']);
            WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'disk' => 'local', 'path' => "weekly-video/week-{$position}.mp4", 'original_filename' => "week-{$position}.mp4", 'mime_type' => 'video/mp4', 'size_bytes' => 100, 'uploaded_at' => now()]);

            foreach (range(1, $rows) as $activityPosition) {
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
