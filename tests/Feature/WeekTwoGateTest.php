<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\SubmissionWorkflowService;
use App\Services\WeekTwoGateService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeekTwoGateTest extends TestCase
{
    use RefreshDatabase;

    private function setupWeekTwo(): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 2)->firstOrFail();

        return [$week, $week->gates()->firstOrFail()];
    }

    public function test_week_three_stays_locked_until_gate_is_passed(): void
    {
        [$week2, $gate] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $this->actingAs($participant)->get('/training/weeks/'.$week2->program->weeks()->where('week_number', 3)->first()->id)->assertForbidden();

        foreach ($week2->activities as $activity) {
            $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
            $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => ['artifact_context' => 'x'], 'submitted_at' => now()]);
            foreach ($activity->requirements()->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->where('is_required', true) as $evidence) {
                SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_requirement_id' => $evidence->id, 'evidence_type' => $evidence->evidence_type, 'title' => 'Proof', 'content' => in_array($evidence->evidence_type, ['text']) ? 'Evidence' : null, 'url' => in_array($evidence->evidence_type, ['video', 'url', 'link', 'repository']) ? 'https://www.loom.com/share/week-2-proof' : null]);
            }
            foreach (range(1, 5) as $day) {
                DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => now()->subDays($day + $activity->position)->toDateString(), 'content' => 'Daily log']);
            }
        }
        $this->actingAs($participant)->get('/training/weeks/'.$week2->program->weeks()->where('week_number', 3)->first()->id)->assertForbidden();
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week2, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all()])->assertSessionHasErrors('gate');
        WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week2->id, 'disk' => 'local', 'path' => 'weekly-video/week-2.mp4', 'original_filename' => 'week-2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 100, 'uploaded_at' => now()]);
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week2, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all()])->assertRedirect();
        $this->actingAs($participant)->get('/training/weeks/'.$week2->program->weeks()->where('week_number', 3)->first()->id)->assertOk();
    }

    public function test_gate_cannot_pass_with_incomplete_requirements(): void
    {
        [$week2, $gate] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $response = $this->actingAs($coach)->post(route('training.coach.gates.update', [$week2, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all()]);
        $response->assertSessionHasErrors('gate');
        $this->assertDatabaseMissing('gate_evaluations', ['training_gate_id' => $gate->id, 'user_id' => $participant->id, 'status' => 'passed']);
    }

    public function test_week_two_template_fields_are_configured_for_each_activity(): void
    {
        [$week2] = $this->setupWeekTwo();
        $expected = [
            'Validation Checklist' => ['issue_caught', 'action_taken'],
            'AI Output Error Detection' => ['actual_truth', 'how_caught', 'what_i_did', 'final_result'],
            'Pre-acceptance Check' => ['affected_scope', 'validation_performed', 'decision', 'final_notes'],
        ];

        foreach ($expected as $title => $keys) {
            $activity = $week2->activities->firstWhere('title', $title);
            $actual = $activity->requirements()->where('requirement_type', 'submission_field')->get()->filter(fn ($requirement) => ! ($requirement->configuration['deprecated'] ?? false))->values()->map(fn ($requirement) => $requirement->configuration['key'])->all();
            $this->assertSame($keys, $actual, $title.' current field definition changed unexpectedly.');
            $this->assertEmpty(array_intersect(['artifact_context', 'feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'prompt', 'ai_output'], $actual), $title.' exposes duplicated artifact context.');
        }

        $activity1 = $week2->activities->firstWhere('title', 'Validation Checklist');
        $actual = $activity1->requirements()->where('requirement_type', 'submission_field')->get()->filter(fn ($requirement) => ! ($requirement->configuration['deprecated'] ?? false))->values()->map(fn ($requirement) => $requirement->configuration['key'])->all();
        $this->assertEmpty(array_intersect(['checklist_outcomes', 'source_check', 'scope_check', 'spec_check', 'cross_check', 'run_check', 'log_check'], $actual));
    }

    public function test_exact_ai_interaction_values_persist_in_submission_version(): void
    {
        [$week2] = $this->setupWeekTwo();
        $participant = User::factory()->create(['training_path' => 'manual']);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $activity = $week2->activities->firstWhere('title', 'AI Output Error Detection');
        $fields = app(SubmissionWorkflowService::class)->fieldDefinitions($activity, $participant);
        $values = collect($fields)->mapWithKeys(fn ($field): array => [$field['key'] => $field['key'] === 'thread_drive_link' ? '' : "Entered {$field['key']}"])->all();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week2, $activity]), ['field_values' => $values])->assertRedirect();
        $version = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $participant->id)->firstOrFail()->versions()->firstOrFail();

        $this->assertSame($values['actual_truth'], $version->field_values['actual_truth']);
    }

    public function test_each_training_week_requires_five_logs(): void
    {
        [$week2, $gate] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $activity = $week2->activities->first();

        foreach (range(1, 4) as $day) {
            DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => now()->subDays($day)->toDateString(), 'content' => 'Log']);
        }
        $this->assertArrayHasKey('daily_logs', app(WeekTwoGateService::class)->readiness($participant, $gate));

        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => now()->toDateString(), 'content' => 'Log']);
        $this->assertArrayNotHasKey('daily_logs', app(WeekTwoGateService::class)->readiness($participant, $gate));
    }

    public function test_each_official_gate_has_exactly_one_required_weekly_video_criterion(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();

        foreach ([2, 3, 4] as $weekNumber) {
            $gate = $program->weeks()->where('week_number', $weekNumber)->firstOrFail()->gates()->firstOrFail();
            $criteria = $gate->criteria()->where('criterion_type', 'weekly_video')->get();

            $this->assertCount(1, $criteria);
            $this->assertSame('Required Weekly Video Recording', $criteria->first()->title);
            $this->assertTrue($criteria->first()->is_required);
        }
    }

    public function test_participant_and_coach_gate_views_show_weekly_video_status(): void
    {
        [$week2, $gate] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        $this->actingAs($participant)->get(route('training.gates.show', [$week2, $gate]))
            ->assertOk()
            ->assertSee('Weekly Video Recording')
            ->assertSee('Missing')
            ->assertSee('Upload Video Recording');
        $this->actingAs($coach)->get(route('training.coach.gates.show', [$week2, $participant, $gate]))
            ->assertOk()
            ->assertSee('Required Weekly Video Recording')
            ->assertSee('Missing');
    }

    public function test_gate_view_links_to_inline_weekly_video_preview_not_download(): void
    {
        Storage::fake('local');
        [$week2, $gate] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $recording = WeeklyVideoRecording::create([
            'user_id' => $participant->id,
            'training_week_id' => $week2->id,
            'disk' => 'local',
            'path' => 'weekly-video/'.$participant->id.'/week-2.mp4',
            'original_filename' => 'week-2.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 4,
            'uploaded_at' => now(),
        ]);
        Storage::disk('local')->put($recording->path, 'video');

        $previewUrl = route('training.video-recording.view', [$week2, $recording]);
        $downloadUrl = route('training.video-recording.download', [$week2, $recording]);

        $this->actingAs($participant)->get(route('training.gates.show', [$week2, $gate]))
            ->assertOk()
            ->assertSee($previewUrl, false)
            ->assertDontSee($downloadUrl, false);
        $this->actingAs($coach)->get(route('training.coach.gates.show', [$week2, $participant, $gate]))
            ->assertOk()
            ->assertSee($previewUrl, false)
            ->assertDontSee($downloadUrl, false);
    }

    public function test_activity_three_submission_does_not_contain_the_weekly_video_requirement(): void
    {
        [$week2] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $activity = $week2->activities->firstWhere('position', 3);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week2, $activity]), ['field_values' => []])->assertRedirect();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))
            ->assertOk()
            ->assertDontSee('Loom')
            ->assertDontSee('Weekly Video Recording');
    }

    public function test_week_four_gate_cannot_pass_without_weekly_video(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week4 = $program->weeks()->where('week_number', 4)->firstOrFail();
        $gate = $week4->gates()->firstOrFail();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        $this->assertArrayHasKey('weekly_video', app(WeekTwoGateService::class)->readiness($participant, $gate));
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week4, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all()])->assertSessionHasErrors('gate');
    }

    public function test_coach_cannot_mark_weekly_video_met_without_recording(): void
    {
        [$week2, $gate] = $this->setupWeekTwo();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $video = $gate->criteria()->where('criterion_type', 'weekly_video')->firstOrFail();
        $criteria = $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => $id === $video->id ? 'met' : 'not_met'])->all();

        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week2, $participant, $gate]), ['status' => 'needs_revision', 'criteria' => $criteria])->assertSessionHasErrors('criteria.'.$video->id);
    }

    public function test_required_evidence_is_checked_on_the_latest_submitted_version(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create())->create(['is_required' => true, 'minimum_count' => 1]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $participant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'resubmitted']);
        $firstVersion = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted']);
        $latestVersion = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'resubmitted']);
        SubmissionEvidence::factory()->for($firstVersion, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();

        $this->assertArrayHasKey('evidence_1', app(WeekTwoGateService::class)->readiness($participant, $gate));

        SubmissionEvidence::factory()->for($latestVersion, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();

        $this->assertArrayNotHasKey('evidence_1', app(WeekTwoGateService::class)->readiness($participant, $gate));
    }

    public function test_legacy_weekly_loom_link_satisfies_the_weekly_video_requirement(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 3]);
        $loomRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'gate_evidence']))->create(['evidence_type' => 'url']);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_loom']);
        $participant = User::factory()->create();

        $this->assertArrayHasKey('weekly_video', app(WeekTwoGateService::class)->readiness($participant, $gate));

        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($loomRequirement, 'evidenceRequirement')->create(['evidence_type' => 'url', 'url' => 'https://www.loom.com/share/week-2']);

        $this->assertArrayNotHasKey('weekly_video', app(WeekTwoGateService::class)->readiness($participant, $gate));
    }

    public function test_a_gate_without_a_weekly_video_criterion_can_be_ready_and_pass_without_a_video(): void
    {
        ['week' => $week, 'gate' => $gate, 'participant' => $participant] = $this->gateWithACompletedWeek();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        $this->assertSame([], app(WeekTwoGateService::class)->readiness($participant, $gate));

        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 'met'])->all()])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('gate_evaluations', ['training_gate_id' => $gate->id, 'user_id' => $participant->id, 'status' => 'passed']);
    }

    public function test_a_gate_with_a_weekly_video_criterion_still_requires_the_video(): void
    {
        ['week' => $week, 'gate' => $gate, 'participant' => $participant] = $this->gateWithACompletedWeek();
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);

        $this->assertSame(['weekly_video'], array_keys(app(WeekTwoGateService::class)->readiness($participant, $gate)));

        WeeklyVideoRecording::create(['user_id' => $participant->id, 'training_week_id' => $week->id, 'disk' => 'local', 'path' => 'weekly-video/week-2.mp4', 'original_filename' => 'week-2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 100, 'uploaded_at' => now()]);

        $this->assertSame([], app(WeekTwoGateService::class)->readiness($participant, $gate));
    }

    #[DataProvider('latestAttemptStates')]
    public function test_latest_attempt_is_authoritative_for_gate_readiness(array $states, bool $ready): void
    {
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        TrainingGate::factory()->for($week, 'week')->create();
        $participant = User::factory()->create();
        foreach (range(1, 5) as $day) {
            DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => now()->subDays($day)->toDateString(), 'content' => 'Log']);
        }
        foreach ($states as $attempt => $status) {
            $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'attempt_number' => $attempt, 'status' => $status, 'submitted_at' => in_array($status, ['submitted', 'approved'], true) ? now() : null]);
            $submission->versions()->create(['version_number' => 1, 'status' => in_array($status, ['submitted', 'approved'], true) ? $status : 'draft', 'field_values' => []]);
        }
        $result = app(WeekTwoGateService::class)->readiness($participant, $week->gates()->first());
        $this->assertSame($ready, ! array_key_exists('activity_'.$activity->position, $result));
    }

    public static function latestAttemptStates(): array
    {
        return [
            'failed then submitted' => [['failed', 'submitted'], true],
            'failed then under review' => [['failed', 'under_review'], false],
            'failed then needs revision' => [['failed', 'needs_revision'], false],
            'failed then approved' => [['failed', 'approved'], true],
            'failed then failed' => [['failed', 'failed'], false],
            'approved then draft' => [['approved', 'draft'], false],
            'approved then under review' => [['approved', 'under_review'], false],
            'approved then failed' => [['approved', 'failed'], false],
            'submitted then draft' => [['submitted', 'draft'], false],
            'submitted then approved' => [['submitted', 'approved'], true],
        ];
    }

    /**
     * A Week 2 Gate with one required activity criterion and no weekly video criterion, and a participant who
     * submitted the week's only activity and logged five daily logs on it.
     *
     * @return array{week: TrainingWeek, gate: TrainingGate, participant: User}
     */
    private function gateWithACompletedWeek(): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create();
        $participant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        foreach (range(1, 5) as $day) {
            DailyLog::factory()->for($participant, 'participant')->for($activity)->create(['logged_on' => now()->subDays($day)->toDateString()]);
        }

        return ['week' => $week, 'gate' => $gate->load('criteria'), 'participant' => $participant];
    }
}
