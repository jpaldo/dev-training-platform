<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Enums\TrainingPath;
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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TrainingDomainTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_training_content_supports_path_specific_requirements_and_evidence_rules(): void
    {
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $requirement = ActivityRequirement::factory()->for($activity)->create([
            'participant_path' => 'automation',
            'requirement_type' => 'deliverable',
        ]);
        $evidenceRequirement = EvidenceRequirement::factory()->for($requirement)->create([
            'evidence_type' => 'link',
            'configuration' => ['accepted_schemes' => ['https']],
        ]);

        $this->assertSame($program->id, $week->program->id);
        $this->assertSame($week->id, $activity->week->id);
        $this->assertSame(TrainingPath::Automation, $requirement->fresh()->participant_path);
        $this->assertSame(['accepted_schemes' => ['https']], $evidenceRequirement->configuration);
    }

    public function test_submission_versions_keep_evidence_attached_to_the_submitted_attempt(): void
    {
        $participant = User::factory()->create();
        $activity = Activity::factory()->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create([
            'status' => 'submitted',
            'field_values' => ['finding' => 'The selector is fabricated.'],
            'submitted_at' => now(),
        ]);
        $evidence = SubmissionEvidence::factory()->for($version)->create([
            'evidence_type' => 'link',
            'content' => null,
            'url' => 'https://example.test/run/42',
        ]);

        $this->assertSame($submission->id, $version->submission->id);
        $this->assertSame($version->id, $evidence->submissionVersion->id);
        $this->assertSame('https://example.test/run/42', $evidence->url);
    }

    public function test_gate_evaluation_tracks_each_configurable_criterion_for_a_participant(): void
    {
        $participant = User::factory()->create();
        $gate = TrainingGate::factory()->create();
        $criterion = GateCriterion::factory()->for($gate, 'gate')->create([
            'criterion_type' => 'coach_review',
            'position' => 1,
        ]);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create();
        $criterionEvaluation = GateCriterionEvaluation::factory()
            ->for($evaluation)
            ->for($criterion, 'criterion')
            ->create(['status' => 'met']);

        $this->assertSame($gate->id, $evaluation->gate->id);
        $this->assertSame($evaluation->id, $criterionEvaluation->gateEvaluation->id);
        $this->assertSame(GateCriterionStatus::Met, $criterionEvaluation->status);
    }
}
