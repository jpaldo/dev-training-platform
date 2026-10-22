<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeekThreeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();

        return [$program->weeks()->where('week_number', 3)->firstOrFail(), $program->weeks()->where('week_number', 4)->firstOrFail()];
    }

    private function unlockWeekThree(User $participant, $week3): void
    {
        $week2 = $week3->program->weeks()->where('week_number', 2)->first();
        GateEvaluation::create(['training_gate_id' => $week2->gates()->first()->id, 'user_id' => $participant->id, 'status' => 'passed', 'evaluated_at' => now()]);
    }

    public function test_week_three_has_all_official_activities_and_path_guidance(): void
    {
        [$week3] = $this->fixture();
        $manual = User::factory()->create(['training_path' => 'manual']);
        $automation = User::factory()->create(['training_path' => 'automation']);
        foreach ([$manual, $automation] as $user) {
            $this->unlockWeekThree($user, $week3);
            $this->actingAs($user)->post(route('participant.attendance.check-in'))->assertRedirect();
            $this->actingAs($user)->get(route('training.weeks.show', $week3))->assertOk();
            $response = $this->actingAs($user)->get(route('training.activities.show', [$week3, $week3->activities->first()]));
            $response->assertOk()->assertSee('Failure');
        }
        $this->assertCount(3, $week3->activities);
    }

    public function test_week_four_unlocks_only_after_week_three_gate_passes(): void
    {
        [$week3, $week4] = $this->fixture();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $this->unlockWeekThree($participant, $week3);
        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertForbidden();
        foreach ($week3->activities as $activity) {
            $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
            $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => [], 'submitted_at' => now()]);
            foreach ($activity->requirements()->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->where('is_required', true) as $evidence) {
                SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_requirement_id' => $evidence->id, 'evidence_type' => $evidence->evidence_type, 'title' => 'Deliverable', 'disk' => 'local', 'path' => 'private/proof.pdf']);
            }
        }
        $gate = $week3->gates->first();
        WeeklyVideoRecording::create([
            'user_id' => $participant->id,
            'training_week_id' => $week3->id,
            'disk' => 'local',
            'path' => 'weekly-video/week-3.mp4',
            'original_filename' => 'week-3.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 100,
            'uploaded_at' => now(),
        ]);
        $criteria = $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all();
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week3, $participant, $gate]), ['status' => 'passed', 'criteria' => $criteria])->assertRedirect();
        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertOk();
    }

    public function test_week_three_gate_rejects_missing_packet_requirements(): void
    {
        [$week3] = $this->fixture();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $this->unlockWeekThree($participant, $week3);
        $gate = $week3->gates->first();
        $response = $this->actingAs($coach)->post(route('training.coach.gates.update', [$week3, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all()]);
        $response->assertSessionHasErrors('gate');
    }
}
