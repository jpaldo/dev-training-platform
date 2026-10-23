<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
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
use App\Models\WeeklyVideoRecording;
use App\Services\GateReadinessService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GateReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_week_lists_unsubmitted_activities_missing_evidence_and_a_missing_video_only_with_a_video_criterion(): void
    {
        $weeks = $this->threeWeekProgram();
        $gates = $weeks->pluck('gate');
        $participant = User::factory()->create();
        $finisher = User::factory()->create();
        foreach ($weeks as $week) {
            $this->handIn($participant, $week['submitted']);
            $this->handIn($finisher, $week['unsubmitted'], $week['evidence']['unsubmitted']);
            $this->handIn($finisher, $week['submitted'], $week['evidence']['submitted']);
            $this->uploadWeeklyVideo($finisher, $week['week']);
        }

        $readiness = app(GateReadinessService::class)->readinessForParticipants(collect([$participant, $finisher]), $gates);

        $withoutVideo = [
            'activity_1' => 'Activity 1 must have a submitted version.',
            'evidence_2' => 'Required evidence for Activity 2 is missing.',
        ];
        $this->assertSame($withoutVideo, $readiness->get($participant->id)->get($gates[0]->id));
        $this->assertSame([
            ...$withoutVideo,
            'weekly_video' => 'Required Weekly Video Recording is missing. Upload the video before this week can be marked completed.',
        ], $readiness->get($participant->id)->get($gates[1]->id));
        $this->assertSame($withoutVideo, $readiness->get($participant->id)->get($gates[2]->id));
        foreach ($gates as $gate) {
            $this->assertSame([], $readiness->get($finisher->id)->get($gate->id));
        }
    }

    public function test_the_weekly_video_is_not_required_without_a_video_criterion_and_otherwise_follows_the_upload_and_review(): void
    {
        $weeks = $this->threeWeekProgram();
        [$weekOne, $weekTwo, $weekThree] = $weeks->pluck('week')->all();
        [$gateOne, $gateTwo, $gateThree] = $weeks->pluck('gate')->all();
        $participant = User::factory()->create();
        $service = app(GateReadinessService::class);

        $this->assertSame(['status' => 'not_required', 'url' => null, 'criterion' => null, 'recording' => null], $service->weeklyVideo($participant, $gateOne));
        $this->assertSame('missing', $service->weeklyVideo($participant, $gateTwo)['status']);
        $this->assertTrue($service->weeklyVideo($participant, $gateTwo)['criterion']->is($weeks[1]['video_criterion']));

        $this->uploadWeeklyVideo($participant, $weekOne);
        $this->uploadWeeklyVideo($participant, $weekThree);
        $this->assertSame('not_required', $service->weeklyVideo($participant, $gateThree)['status']);
        $this->assertSame('missing', $service->weeklyVideo($participant, $gateTwo)['status']);

        $recording = $this->uploadWeeklyVideo($participant, $weekTwo);
        $awaiting = $service->weeklyVideo($participant, $gateTwo);
        $this->assertSame('awaiting_review', $awaiting['status']);
        $this->assertSame($recording->driveViewUrl(), $awaiting['url']);
        $this->assertTrue($awaiting['recording']->is($recording));
        $this->assertArrayNotHasKey('weekly_video', $service->readiness($participant, $gateTwo));

        $evaluation = GateEvaluation::factory()->for($gateTwo, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        GateCriterionEvaluation::factory()->for($evaluation)->for($weeks[1]['video_criterion'], 'criterion')->create(['status' => GateCriterionStatus::Met]);
        $this->assertSame('met', $service->weeklyVideo($participant, $gateTwo)['status']);
    }

    public function test_required_evidence_is_checked_on_the_latest_handed_in_version(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity))->create(['is_required' => true, 'minimum_count' => 1]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $participant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'resubmitted']);
        $firstVersion = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted']);
        $latestVersion = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'resubmitted']);
        SubmissionEvidence::factory()->for($firstVersion, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();

        $this->assertArrayHasKey('evidence_1', app(GateReadinessService::class)->readiness($participant, $gate));

        SubmissionEvidence::factory()->for($latestVersion, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();

        $this->assertArrayNotHasKey('evidence_1', app(GateReadinessService::class)->readiness($participant, $gate));
    }

    public function test_inactive_activities_optional_evidence_and_gate_evidence_are_not_required(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity))->create(['is_required' => false]);
        EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->state(['requirement_type' => 'gate_evidence']))->create(['is_required' => true]);
        Activity::factory()->for($week, 'week')->create(['position' => 2, 'is_active' => false]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $participant = User::factory()->create();
        $this->handIn($participant, $activity);

        $this->assertSame([], app(GateReadinessService::class)->readiness($participant, $gate));
    }

    #[DataProvider('latestAttemptStates')]
    public function test_the_latest_attempt_decides_whether_an_activity_is_handed_in(array $states, bool $isHandedIn): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $participant = User::factory()->create();
        foreach ($states as $attempt => $status) {
            $isHandedInStatus = in_array($status, ['submitted', 'approved'], true);
            $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['attempt_number' => $attempt, 'status' => $status, 'submitted_at' => $isHandedInStatus ? now() : null]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $isHandedInStatus ? $status : 'draft']);
        }

        $readiness = app(GateReadinessService::class)->readiness($participant, $gate);

        $this->assertSame($isHandedIn, ! array_key_exists('activity_1', $readiness));
    }

    /**
     * The statuses of the participant's attempts, by attempt number, and whether the activity counts as handed in.
     *
     * @return array<string, array{array<int, string>, bool}>
     */
    public static function latestAttemptStates(): array
    {
        return [
            'failed then submitted' => [[1 => 'failed', 2 => 'submitted'], true],
            'failed then under review' => [[1 => 'failed', 2 => 'under_review'], false],
            'failed then needs revision' => [[1 => 'failed', 2 => 'needs_revision'], false],
            'failed then approved' => [[1 => 'failed', 2 => 'approved'], true],
            'failed then failed' => [[1 => 'failed', 2 => 'failed'], false],
            'approved then draft' => [[1 => 'approved', 2 => 'draft'], false],
            'approved then under review' => [[1 => 'approved', 2 => 'under_review'], false],
            'approved then failed' => [[1 => 'approved', 2 => 'failed'], false],
            'submitted then draft' => [[1 => 'submitted', 2 => 'draft'], false],
            'submitted then approved' => [[1 => 'submitted', 2 => 'approved'], true],
        ];
    }

    public function test_a_coach_cannot_mark_the_weekly_video_met_before_it_is_uploaded(): void
    {
        $weekTwo = $this->threeWeekProgram()[1];
        ['week' => $week, 'gate' => $gate, 'video_criterion' => $videoCriterion] = $weekTwo;
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $review = ['status' => 'needs_revision', 'criteria' => [$videoCriterion->id => 'met']];

        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week, $participant, $gate]), $review)
            ->assertSessionHasErrors(['criteria.'.$videoCriterion->id => 'The Weekly Video Recording cannot be marked Met until a video is uploaded.']);
        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);

        $this->uploadWeeklyVideo($participant, $week);

        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week, $participant, $gate]), $review)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('gate_criterion_evaluations', ['gate_criterion_id' => $videoCriterion->id, 'status' => 'met']);
    }

    /**
     * An active program with weeks 1 to 3. Each week has a Gate with a required deliverable criterion and two active
     * activities with one required evidence item each; only week 2's Gate also has a weekly video criterion.
     *
     * @return Collection<int, array{week: TrainingWeek, gate: TrainingGate, video_criterion: ?GateCriterion, unsubmitted: Activity, submitted: Activity, evidence: array{unsubmitted: EvidenceRequirement, submitted: EvidenceRequirement}}>
     */
    private function threeWeekProgram(): Collection
    {
        $program = Program::factory()->create();

        return collect([1, 2, 3])->map(function (int $weekNumber) use ($program): array {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
            $gate = TrainingGate::factory()->for($week, 'week')->create();
            GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
            $videoCriterion = $weekNumber === 2
                ? GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO])
                : null;
            $unsubmitted = Activity::factory()->for($week, 'week')->create(['position' => 1]);
            $submitted = Activity::factory()->for($week, 'week')->create(['position' => 2]);

            return [
                'week' => $week,
                'gate' => $gate,
                'video_criterion' => $videoCriterion,
                'unsubmitted' => $unsubmitted,
                'submitted' => $submitted,
                'evidence' => [
                    'unsubmitted' => EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($unsubmitted))->create(),
                    'submitted' => EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($submitted))->create(),
                ],
            ];
        });
    }

    /**
     * Hand in the activity for the participant: a submitted attempt whose version holds evidence for the given
     * requirement, or no evidence without one.
     */
    private function handIn(User $participant, Activity $activity, ?EvidenceRequirement $evidenceRequirement = null): void
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        if ($evidenceRequirement) {
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
        }
    }

    private function uploadWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();
    }
}
