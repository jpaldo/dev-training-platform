<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ProgramEnrollmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProgramSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_version_of_an_attempt_at_another_curriculums_activity_is_not_found(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'program' => $program] = $this->scene();
        $otherWeek = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $otherActivity = Activity::factory()->for($otherWeek, 'week')->create();
        $otherProgram = $otherWeek->program;
        $otherProgram->participants()->attach($participant, ['joined_at' => now()]);
        $submitted = $this->version($participant, $otherActivity, SubmissionStatus::Submitted);
        $draft = $this->version($participant, $otherActivity, SubmissionStatus::Draft);
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'text']);

        $this->actingAs($participant)->get(route('training.submission.show', [$otherProgram, $otherWeek, $otherActivity, $submitted]))->assertOk();
        $this->actingAs($participant)->get(route('training.submission.show', [$program, $week, $activity, $submitted]))->assertNotFound();
        $this->actingAs($participant)->post(route('training.submission.submit', [$program, $week, $activity, $draft]))->assertNotFound();
        $this->actingAs($participant)->post(route('training.evidence.store', [$program, $week, $activity, $draft]), ['evidence_requirement_id' => $requirement->id, 'content' => 'Wrong activity'])->assertNotFound();

        $this->assertSame(SubmissionStatus::Draft, $draft->fresh()->status);
        $this->assertDatabaseCount('submission_evidence', 0);
    }

    public function test_removing_the_participant_from_the_curriculum_keeps_their_submissions_and_closes_the_form(): void
    {
        ['participant' => $participant, 'week' => $week, 'activity' => $activity, 'program' => $program] = $this->scene();
        $submission = $this->version($participant, $activity, SubmissionStatus::Submitted)->submission;

        app(ProgramEnrollmentService::class)->removeParticipant($program, $participant, User::factory()->create(['role' => 'admin']));

        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'activity_id' => $activity->id, 'user_id' => $participant->id]);
        $this->assertDatabaseHas('submission_versions', ['activity_submission_id' => $submission->id]);
        $this->actingAs($participant)->get(route('training.submission.edit', [$program, $week, $activity]))->assertForbidden();
    }

    /**
     * A checked-in participant in a curriculum whose open Week 1 has an activity.
     *
     * @return array{participant: User, week: TrainingWeek, activity: Activity, program: Program}
     */
    private function scene(): array
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $program = $week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return compact('participant', 'week', 'activity', 'program');
    }

    /**
     * A version with the given status of a new attempt by the participant at the activity.
     */
    private function version(User $participant, Activity $activity, SubmissionStatus $status): SubmissionVersion
    {
        $submission = ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => $status]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create([
            'status' => $status,
            'submitted_at' => $status === SubmissionStatus::Draft ? null : now(),
        ]);
    }
}
