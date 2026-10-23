<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompletedTrainingSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDED = 'This training session has ended.';

    public function test_after_the_end_the_participant_views_every_week_with_their_work_and_scores_but_no_write_buttons(): void
    {
        ['participant' => $participant, 'mentor' => $mentor, 'session' => $session, 'week1' => $week1, 'week2' => $week2, 'reviewedActivity' => $reviewedActivity, 'week2Activity' => $week2Activity] = $this->scene();
        $version = $this->attempt($participant, $session, $reviewedActivity, SubmissionStatus::Approved);
        ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => $mentor->id, 'status' => 'published', 'total_score' => 88, 'performance_band' => 'Proficient']);
        WeeklyVideoRecording::factory()->for($session)->for($participant, 'participant')->for($week2, 'week')->create(['name' => 'Week 2 walkthrough.mp4']);
        $this->reviewWeekOne($mentor, $participant, $session, $week1, GateEvaluationStatus::NeedsRevision);
        $this->actingAs($participant)->get(route('training.weeks.show', [$session, $week2]))->assertForbidden();

        $this->travelTo('2026-11-23 09:00:00');

        $this->actingAs($participant)->get(route('training.sessions.show', $session))
            ->assertOk()
            ->assertSee('This training session has ended. You can still view every week')
            ->assertDontSee('Complete your Week 1 deliverables to unlock')
            ->assertSee(route('training.activities.show', [$session, $week2, $week2Activity]), false)
            ->assertSee('Week 2 walkthrough.mp4')
            ->assertDontSee('Choose Video')
            ->assertDontSee('Change Video');
        $this->actingAs($participant)->get(route('training.weeks.show', [$session, $week2]))
            ->assertOk()
            ->assertSee('Week 2 walkthrough.mp4')
            ->assertDontSee('Change Video');
        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week2, $week2Activity]))
            ->assertOk()
            ->assertSee('This training session has ended. Your work stays available to view.')
            ->assertDontSee('Start submission');
        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week1, $reviewedActivity]))
            ->assertOk()
            ->assertSee('88 / 100');
        $this->actingAs($participant)->get(route('training.submission.show', [$session, $week1, $reviewedActivity, $version]))->assertOk();
        $this->actingAs($participant)->get(route('training.video-recording.edit', [$session, $week2]))
            ->assertOk()
            ->assertSee(self::ENDED)
            ->assertDontSee('data-weekly-video-form', false);
    }

    public function test_after_the_end_the_participant_cannot_start_continue_submit_or_revise_work(): void
    {
        ['participant' => $participant, 'session' => $session, 'week1' => $week1, 'reviewedActivity' => $reviewedActivity, 'newActivity' => $newActivity] = $this->scene();
        $submitted = $this->attempt($participant, $session, $reviewedActivity, SubmissionStatus::Submitted);
        $draft = $this->attempt($participant, $session, $newActivity, SubmissionStatus::Draft);
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($newActivity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'text']);

        $this->travelTo('2026-11-23 09:00:00');

        $this->actingAs($participant)->get(route('training.submission.edit', [$session, $week1, $newActivity]))
            ->assertForbidden()
            ->assertSee(self::ENDED);
        $this->actingAs($participant)->post(route('training.submission.draft', [$session, $week1, $newActivity]), ['field_values' => ['finding' => 'Too late']])
            ->assertForbidden()
            ->assertSee(self::ENDED);
        $this->actingAs($participant)->post(route('training.evidence.store', [$session, $week1, $newActivity, $draft]), ['evidence_requirement_id' => $requirement->id, 'content' => 'Too late'])
            ->assertForbidden();
        $this->actingAs($participant)->post(route('training.submission.submit', [$session, $week1, $newActivity, $draft]))
            ->assertForbidden();
        $this->actingAs($participant)->post(route('training.submission.revise', [$session, $week1, $reviewedActivity]))
            ->assertForbidden()
            ->assertSee(self::ENDED);

        $this->assertSame(SubmissionStatus::Draft, $draft->fresh()->status);
        $this->assertSame([], $draft->fresh()->field_values ?? []);
        $this->assertDatabaseCount('submission_evidence', 0);
        $this->assertSame(1, $submitted->submission->versions()->count());
    }

    public function test_after_the_end_the_participant_cannot_choose_a_weekly_video_or_ask_for_a_review_of_a_failed_attempt(): void
    {
        Http::fake();
        ['participant' => $participant, 'session' => $session, 'week2' => $week2, 'week2Activity' => $week2Activity] = $this->scene();
        $failed = $this->attempt($participant, $session, $week2Activity, SubmissionStatus::Submitted, SubmissionStatus::Failed);

        $this->travelTo('2026-11-23 09:00:00');

        $this->actingAs($participant)->post(route('training.video-recording.store', [$session, $week2]), ['drive_file_id' => 'video-file-1'])
            ->assertForbidden()
            ->assertSee(self::ENDED);
        $this->actingAs($participant)->post(route('training.recovery.store', $failed->submission), ['message' => 'Please look again'])
            ->assertForbidden()
            ->assertSee(self::ENDED);
        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week2, $week2Activity]))
            ->assertOk()
            ->assertSee('This training session has ended, so you can no longer ask for a review of this attempt.')
            ->assertDontSee(route('training.recovery.store', $failed->submission), false);

        $this->assertDatabaseEmpty('weekly_video_recordings');
        $this->assertDatabaseEmpty('activity_recovery_requests');
        Http::assertNothingSent();
    }

    public function test_an_activity_a_mentor_sends_back_after_the_end_can_be_revised_and_resubmitted_once(): void
    {
        ['participant' => $participant, 'mentor' => $mentor, 'session' => $session, 'week1' => $week1, 'reviewedActivity' => $reviewedActivity] = $this->scene();
        $submitted = $this->attempt($participant, $session, $reviewedActivity, SubmissionStatus::Submitted);

        $this->travelTo('2026-11-23 09:00:00');
        $this->actingAs($mentor)->post(route('coach.reviews.store', $submitted), ['decision' => 'needs_revision', 'comments' => 'Add the missing finding.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week1, $reviewedActivity]))
            ->assertOk()
            ->assertSee('Revise submission')
            ->assertDontSee('This training session has ended. Your work stays available to view.');
        $this->actingAs($participant)->post(route('training.submission.revise', [$session, $week1, $reviewedActivity]))
            ->assertRedirect(route('training.submission.edit', [$session, $week1, $reviewedActivity]));
        $this->actingAs($participant)->get(route('training.submission.edit', [$session, $week1, $reviewedActivity]))->assertOk();
        $this->actingAs($participant)->post(route('training.submission.draft', [$session, $week1, $reviewedActivity]), ['field_values' => ['finding' => 'The missing finding']])
            ->assertRedirect();
        $revision = $submitted->submission->versions()->where('version_number', 2)->sole();
        $this->actingAs($participant)->post(route('training.submission.submit', [$session, $week1, $reviewedActivity, $revision]))
            ->assertSessionHasNoErrors();

        $this->assertSame(SubmissionStatus::Resubmitted, $submitted->submission->fresh()->status);
        $this->assertSame(['finding' => 'The missing finding'], $revision->fresh()->field_values);
        $this->actingAs($participant)->post(route('training.submission.draft', [$session, $week1, $reviewedActivity]), ['field_values' => ['finding' => 'Another change']])
            ->assertForbidden();
    }

    public function test_the_mentor_reviews_an_attempt_resubmitted_after_the_end(): void
    {
        ['participant' => $participant, 'mentor' => $mentor, 'session' => $session, 'reviewedActivity' => $reviewedActivity] = $this->scene();
        $first = $this->attempt($participant, $session, $reviewedActivity, SubmissionStatus::Submitted, SubmissionStatus::Resubmitted);

        $this->travelTo('2026-11-23 09:00:00');
        $resubmitted = SubmissionVersion::factory()->for($first->submission, 'submission')->create(['version_number' => 2, 'status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);

        $this->actingAs($mentor)->get(route('coach.reviews.show', $resubmitted))->assertOk();
        $this->actingAs($mentor)->post(route('coach.reviews.store', $resubmitted), ['decision' => 'approved'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubmissionStatus::Approved, $first->submission->fresh()->status);
    }

    /**
     * A 2-week session from Mon 9 Nov to Sun 22 Nov 2026, as of Tue 17 Nov, with a participant mentored there by a
     * coach. Week 1 has two activities with an optional "Finding" field and a checklist with one deliverable item; Week
     * 2 has one activity and a checklist that asks for the weekly video.
     *
     * @return array{participant: User, mentor: User, session: TrainingSession, week1: TrainingWeek, week2: TrainingWeek, reviewedActivity: Activity, newActivity: Activity, week2Activity: Activity}
     */
    private function scene(): array
    {
        Queue::fake();
        $this->travelTo('2026-11-17 09:00:00');
        $participant = User::factory()->create();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->create();
        $week1 = TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1]);
        $week2 = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        [$reviewedActivity, $newActivity] = collect([1, 2])->map(function (int $position) use ($week1): Activity {
            $activity = Activity::factory()->for($week1, 'week')->create(['position' => $position]);
            ActivityRequirement::factory()->for($activity)->create([
                'requirement_type' => 'submission_field',
                'position' => 1,
                'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => false],
            ]);

            return $activity;
        })->all();
        $week2Activity = Activity::factory()->for($week2, 'week')->create(['position' => 1]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week1, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week2, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        $session = TrainingSession::factory()->for($program)->withParticipants($participant)->withMentors($mentor)->create([
            'weeks_count' => 2,
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-22',
        ]);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $mentor->id]);

        return compact('participant', 'mentor', 'session', 'week1', 'week2', 'reviewedActivity', 'newActivity', 'week2Activity');
    }

    /**
     * The first version, with the given status, of a new attempt by the participant at the activity in the session.
     */
    private function attempt(User $participant, TrainingSession $session, Activity $activity, SubmissionStatus $status, ?SubmissionStatus $attemptStatus = null): SubmissionVersion
    {
        $submittedAt = $status === SubmissionStatus::Draft ? null : now();
        $submission = ActivitySubmission::factory()->for($session)->for($activity)->for($participant, 'participant')->create([
            'status' => $attemptStatus ?? $status,
            'submitted_at' => $submittedAt,
        ]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create([
            'version_number' => 1,
            'status' => $status === SubmissionStatus::Draft ? SubmissionStatus::Draft : SubmissionStatus::Submitted,
            'submitted_at' => $submittedAt,
            'field_values' => [],
        ]);
    }

    /**
     * Post the mentor's review of Week 1's checklist for the participant in the session: completed ticks its item,
     * incomplete leaves it unticked.
     */
    private function reviewWeekOne(User $mentor, User $participant, TrainingSession $session, TrainingWeek $week1, GateEvaluationStatus $status): void
    {
        $gate = $week1->gates()->with('criteria')->sole();

        $this->actingAs($mentor)->post(route('training.coach.gates.update', [$session, $week1, $participant, $gate]), [
            'status' => $status->value,
            'criteria' => $gate->criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => $status === GateEvaluationStatus::Passed ? 'met' : 'not_met'])->all(),
        ])->assertSessionHasNoErrors();
    }
}
