<?php

namespace Tests\Feature;

use App\Enums\ActivityScoreStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\RecoveryRequestStatus;
use App\Enums\ReviewDecision;
use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\MentorAssignmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Only an admin or the participant's current mentor in a session reviews, scores, decides the Gate on, decides recovery
 * requests for and opens the evidence of the participant's work in that session (MEN-3, MEN-4).
 */
class MentorScopeAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REVIEW = ['decision' => 'approved', 'comments' => 'Looks good.'];

    private const SCORE = ['dimensions' => ['D1' => ['points' => 80, 'evidence_reasoning' => 'Clear deliverable.']]];

    private const RECOVERY_DECISION = ['decision' => 'declined', 'coach_response' => 'Keep the failed attempt.'];

    public function test_after_a_change_the_new_mentor_reviews_scores_and_decides_on_the_week_one_work(): void
    {
        ['work' => $work, 'currentMentor' => $ben, 'previousMentor' => $ana, 'participant' => $participant] = $this->fixture();

        $this->actingAs($ben)->post(route('coach.reviews.store', $work['version']), self::REVIEW)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ben)->post(route('coach.scoring.second-reader', $work['version']), $this->secondReading($ben))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ben)->post(route('coach.scoring.store', $work['version']), self::SCORE)->assertRedirect(route('coach.scoring.participant', $participant));
        $this->actingAs($ben)->post($this->gateUrl($work, $participant), $this->completedChecklist($work['criteria']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ben)->post(route('coach.recovery.decide', $work['recoveryRequest']), self::RECOVERY_DECISION)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ben)->get(route('training.evidence.download', $work['evidence']))->assertOk()->assertDownload('report.pdf');

        $this->assertSame(ReviewDecision::Approved, $work['version']->reviews()->where('reviewer_id', $ben->id)->sole()->decision);
        $score = $work['version']->activityScore()->sole();
        $this->assertSame($ben->id, $score->scorer_id);
        $this->assertSame(80, $score->total_score);
        $this->assertSame(70, $score->second_score);
        $this->assertSame(GateEvaluationStatus::Passed, GateEvaluation::query()->whereBelongsTo($work['session'])->sole()->status);
        $this->assertSame(RecoveryRequestStatus::Declined, $work['recoveryRequest']->fresh()->status);

        $earlierReview = $work['earlierVersion']->reviews()->sole();
        $this->assertSame($ana->id, $earlierReview->reviewer_id);
        $this->actingAs($ben)->get(route('coach.reviews.show', $work['earlierVersion']))
            ->assertOk()
            ->assertSee('Decision: Revise · Reviewed by '.$ana->name);
    }

    public function test_after_a_change_the_previous_mentor_is_forbidden_on_the_same_work(): void
    {
        ['work' => $work, 'previousMentor' => $ana, 'participant' => $participant] = $this->fixture();

        $this->assertForbiddenOnWork($ana, $work, $participant);
    }

    public function test_a_mentor_of_the_participant_in_one_session_is_forbidden_on_their_work_in_another(): void
    {
        ['work' => $work, 'otherSessionWork' => $otherSessionWork, 'currentMentor' => $ben, 'otherSessionMentor' => $cy, 'participant' => $participant] = $this->fixture();

        $this->assertForbiddenOnWork($ben, $otherSessionWork, $participant);
        $this->assertForbiddenOnWork($cy, $work, $participant);
    }

    public function test_only_an_admin_and_the_current_mentor_open_the_gate_review_with_the_weekly_video_link(): void
    {
        ['work' => $work, 'admin' => $admin, 'currentMentor' => $ben, 'previousMentor' => $ana, 'otherSessionMentor' => $cy, 'participant' => $participant] = $this->fixture();
        $gatePageUrl = route('training.coach.gates.show', [$work['session'], $work['week'], $participant, $work['gate']]);

        foreach ([$admin, $ben] as $viewer) {
            $this->actingAs($viewer)->get($gatePageUrl)
                ->assertOk()
                ->assertSee('href="'.$work['recording']->driveViewUrl().'"', false)
                ->assertSee('Save Gate Evaluation');
        }

        $this->actingAs($ana)->get($gatePageUrl)->assertForbidden();
        $this->actingAs($cy)->get($gatePageUrl)->assertForbidden();
    }

    public function test_an_admin_keeps_full_access_without_being_a_mentor(): void
    {
        ['work' => $work, 'admin' => $admin, 'participant' => $participant] = $this->fixture();

        $this->actingAs($admin)->post(route('coach.reviews.store', $work['version']), self::REVIEW)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('coach.scoring.second-reader', $work['version']), $this->secondReading($admin))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('coach.scoring.store', $work['version']), self::SCORE)->assertRedirect(route('coach.scoring.participant', $participant));
        $this->actingAs($admin)->post($this->gateUrl($work, $participant), $this->completedChecklist($work['criteria']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('coach.recovery.decide', $work['recoveryRequest']), self::RECOVERY_DECISION)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('training.evidence.download', $work['evidence']))->assertOk();

        $this->assertSame($admin->id, $work['version']->reviews()->sole()->reviewer_id);
        $this->assertSame($admin->id, $work['version']->activityScore()->sole()->scorer_id);
        $this->assertSame($admin->id, GateEvaluation::query()->whereBelongsTo($work['session'])->sole()->reviewer_id);
        $this->assertSame($admin->id, $work['recoveryRequest']->fresh()->reviewed_by);
    }

    /**
     * Open the work's review and Gate review pages and send each mentor action on the work as the user, and assert that
     * every one is forbidden and the actions changed nothing.
     *
     * @param  array{session: TrainingSession, week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, version: SubmissionVersion, earlierVersion: SubmissionVersion, evidence: SubmissionEvidence, recoveryRequest: ActivityRecoveryRequest, recording: WeeklyVideoRecording}  $work
     */
    private function assertForbiddenOnWork(User $user, array $work, User $participant): void
    {
        $this->actingAs($user)->get(route('coach.reviews.show', $work['version']))->assertForbidden();
        $this->actingAs($user)->get(route('training.coach.gates.show', [$work['session'], $work['week'], $participant, $work['gate']]))->assertForbidden();
        $this->actingAs($user)->post(route('coach.reviews.store', $work['version']), self::REVIEW)->assertForbidden();
        $this->actingAs($user)->post(route('coach.scoring.second-reader', $work['version']), $this->secondReading($user))->assertForbidden();
        $this->actingAs($user)->post(route('coach.scoring.store', $work['version']), self::SCORE)->assertForbidden();
        $this->actingAs($user)->post($this->gateUrl($work, $participant), $this->completedChecklist($work['criteria']))->assertForbidden();
        $this->actingAs($user)->post(route('coach.recovery.decide', $work['recoveryRequest']), self::RECOVERY_DECISION)->assertForbidden();
        $this->actingAs($user)->get(route('training.evidence.download', $work['evidence']))->assertForbidden();

        $this->assertSame(0, $work['version']->reviews()->count());
        $score = $work['version']->activityScore()->sole();
        $this->assertSame(50, $score->total_score);
        $this->assertNull($score->second_score);
        $this->assertSame(0, GateEvaluation::query()->whereBelongsTo($work['session'])->count());
        $this->assertSame(RecoveryRequestStatus::Pending, $work['recoveryRequest']->fresh()->status);
    }

    /**
     * Session X, where admin assigned Ana and then, two weeks later, Ben as the participant's mentor (Ana reviewed an
     * earlier version while she was), and session Y of the same program, where Cy mentors the participant. Each session
     * holds the participant's week 1 work (see work()).
     *
     * @return array{admin: User, participant: User, previousMentor: User, currentMentor: User, otherSessionMentor: User, work: array<string, mixed>, otherSessionWork: array<string, mixed>}
     */
    private function fixture(): array
    {
        Storage::fake('local');
        $this->travelTo('2026-11-09 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['name' => 'Pat Participant']);
        $ana = User::factory()->create(['role' => 'coach', 'name' => 'Ana Reyes']);
        $ben = User::factory()->create(['role' => 'coach', 'name' => 'Ben Lim']);
        $cy = User::factory()->create(['role' => 'coach', 'name' => 'Cy Tan']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->withMentors($ana, $ben)->create();
        $otherSession = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->withMentors($cy)->create();
        $assignments = app(MentorAssignmentService::class);
        $assignments->assign($session, $participant, $ana, $admin);
        $assignments->assign($otherSession, $participant, $cy, $admin);

        $work = $this->work($participant, $session, $week);
        $otherSessionWork = $this->work($participant, $otherSession, $week);
        $this->actingAs($ana)->post(route('coach.reviews.store', $work['earlierVersion']), ['decision' => 'needs_revision'])->assertRedirect();

        $this->travel(2)->weeks();
        $assignments->assign($session, $participant, $ben, $admin);

        return [
            'admin' => $admin,
            'participant' => $participant,
            'previousMentor' => $ana,
            'currentMentor' => $ben,
            'otherSessionMentor' => $cy,
            'work' => $work,
            'otherSessionWork' => $otherSessionWork,
        ];
    }

    /**
     * The participant's week 1 work in the session: the week's checklist (one deliverable item and the weekly video
     * item) with a chosen weekly video, an attempt whose first version waits for review and whose second version (with a
     * stored file as evidence and a draft score that needs a second reader) is the one to act on, and a failed attempt
     * at a second activity with a pending recovery request. The week's Gate and activities are shared between sessions.
     *
     * @return array{session: TrainingSession, week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, version: SubmissionVersion, earlierVersion: SubmissionVersion, evidence: SubmissionEvidence, recoveryRequest: ActivityRecoveryRequest, recording: WeeklyVideoRecording}
     */
    private function work(User $participant, TrainingSession $session, TrainingWeek $week): array
    {
        $gate = TrainingGate::query()->whereBelongsTo($week, 'week')->first() ?? TrainingGate::factory()->for($week, 'week')->create();
        $criteria = $gate->criteria()->orderBy('position')->get();
        if ($criteria->isEmpty()) {
            $criteria = collect([
                GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE, 'position' => 1]),
                GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO, 'position' => 2]),
            ]);
        }
        $activities = $week->activities()->orderBy('position')->get();
        if ($activities->isEmpty()) {
            $activities = collect([
                Activity::factory()->for($week, 'week')->withScoringDimensions([['key' => 'D1', 'label' => 'Quality', 'max' => 100]])->create(['position' => 1]),
                Activity::factory()->for($week, 'week')->create(['position' => 2]),
            ]);
        }

        $submission = ActivitySubmission::factory()->for($activities[0])->for($participant, 'participant')->for($session)->create(['status' => 'submitted', 'submitted_at' => now()]);
        $earlierVersion = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'submitted', 'submitted_at' => now()]);
        $path = "evidence/{$session->id}/report.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4');
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'content' => null, 'disk' => 'local', 'path' => $path, 'original_filename' => 'report.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 8]);
        ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => ActivityScoreStatus::Draft, 'total_score' => 50, 'performance_band' => 'Beginning', 'second_reader_required' => true]);

        $failed = ActivitySubmission::factory()->for($activities[1])->for($participant, 'participant')->for($session)->create(['status' => 'failed']);
        $recoveryRequest = ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'message' => 'Please reconsider.', 'requested_at' => now()]);
        $recording = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->for($session)->create();

        return [
            'session' => $session,
            'week' => $week,
            'gate' => $gate,
            'criteria' => $criteria,
            'version' => $version,
            'earlierVersion' => $earlierVersion,
            'evidence' => $evidence,
            'recoveryRequest' => $recoveryRequest,
            'recording' => $recording,
        ];
    }

    /**
     * @param  array{session: TrainingSession, week: TrainingWeek, gate: TrainingGate}  $work
     */
    private function gateUrl(array $work, User $participant): string
    {
        return route('training.coach.gates.update', [$work['session'], $work['week'], $participant, $work['gate']]);
    }

    /**
     * A Completed decision with every checklist item ticked.
     *
     * @param  Collection<int, GateCriterion>  $criteria
     * @return array{status: string, criteria: array<int, string>}
     */
    private function completedChecklist(Collection $criteria): array
    {
        return [
            'status' => 'passed',
            'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
        ];
    }

    /**
     * A second reader's score of 70 by the reader.
     *
     * @return array{second_reader_id: int, second_score: int, second_reasoning: string}
     */
    private function secondReading(User $reader): array
    {
        return ['second_reader_id' => $reader->id, 'second_score' => 70, 'second_reasoning' => 'Agree with the first reader.'];
    }
}
