<?php

namespace Tests\Feature;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachGateReviewPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ready_participant_shows_the_summary_the_checklist_and_a_single_main_action_for_the_decision_form(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 2, extraCriteriaCount: 1, mentor: $coach);
        foreach ($activities as $activity) {
            $this->completeActivity($participant, $activity);
        }
        $recording = $this->chooseWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision', 'comments' => 'Strong week, finish the video.']);
        [$activityCriterion, $videoCriterion] = $criteria;
        GateCriterionEvaluation::factory()->for($evaluation)->for($activityCriterion, 'criterion')->create(['status' => GateCriterionStatus::Met]);
        GateCriterionEvaluation::factory()->for($evaluation)->for($videoCriterion, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);

        $response = $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2 · GATE REVIEW',
                e($gate->title),
                'Participant: '.e($participant->name).'</p>',
                'id="gate-review-main-action"',
                'form="gate-review-form"',
                'Save Gate Evaluation',
                'Gate readiness',
                'For information only. The weekly checklist decides whether the week is completed.',
                '>Checklist</span>',
                '1 / 2',
                'Activities',
                '2 / 2 submitted',
                'Video Recording',
                'Chosen',
                'Weekly checklist',
                'Nothing is ticked automatically.',
                '<input type="hidden" name="criteria['.$activityCriterion->id.']" value="not_met" form="gate-review-form">',
                'type="checkbox"',
                'id="criterion-'.$activityCriterion->id.'"',
                'name="criteria['.$activityCriterion->id.']"',
                'value="met"',
                'form="gate-review-form"',
                'aria-describedby="criterion-'.$activityCriterion->id.'-description"',
                'checked',
                '<label for="criterion-'.$activityCriterion->id.'"',
                e($activityCriterion->title).'</label>',
                'id="criterion-'.$activityCriterion->id.'-description"',
                e($activityCriterion->description),
                '<input type="hidden" name="criteria['.$videoCriterion->id.']" value="not_met" form="gate-review-form">',
                'id="criterion-'.$videoCriterion->id.'"',
                'name="criteria['.$videoCriterion->id.']"',
                'class="mt-0.5 size-5',
                '<label for="criterion-'.$videoCriterion->id.'"',
                'Required Weekly Video Recording</label>',
                'href="'.$recording->driveViewUrl().'" target="_blank" rel="noopener noreferrer"',
                '>View Video<span class="sr-only"> (opens Google Drive in a new tab)</span></a>',
                'Deliverable links',
                'action="'.route('training.coach.gates.update', [$session, $week, $participant, $gate]).'" id="gate-review-form"',
                'Completed needs every checklist item ticked. Incomplete needs at least one item left unticked; the unticked items are recorded as missing.',
                'name="status"',
                '<option value="passed" >Completed</option>',
                '<option value="needs_revision" selected>Incomplete</option>',
                '</select>',
                'name="comments"',
                'Strong week, finish the video.',
            ], false)
            ->assertDontSee('Blockers')
            ->assertDontSee('value="failed"', false)
            ->assertDontSee('Criterion decisions')
            ->assertDontSee('Optional stretch goal');

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'Save Gate Evaluation'));
        $this->assertSame(1, substr_count($content, ' checked'));
        $this->assertSame(0, preg_match_all('/\sdisabled\s/', $content));
    }

    public function test_every_checklist_item_starts_unticked_without_a_saved_evaluation_even_when_the_week_is_complete(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 2, extraCriteriaCount: 2, mentor: $coach);
        foreach ($activities as $activity) {
            $this->completeActivity($participant, $activity);
        }
        $this->chooseWeeklyVideo($participant, $week);

        $content = $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder(['>Checklist</span>', '0 / 3', '2 / 2 submitted', 'Chosen'], false)
            ->assertDontSee('Blockers')
            ->getContent();

        $this->assertSame(3, substr_count($content, 'type="checkbox"'));
        $this->assertSame(0, substr_count($content, ' checked'));
        $this->assertSame(0, substr_count($content, ' selected>'));
    }

    public function test_the_checklist_lists_each_activitys_github_links_from_its_latest_handed_in_version(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 3, extraCriteriaCount: 1, mentor: $coach);
        [$linkedActivity, $draftActivity, $notGitHubActivity] = $activities;
        foreach ($activities as $activity) {
            ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => 1, 'configuration' => ['key' => 'deliverable_url', 'label' => 'Deliverable link', 'type' => 'github_url']]);
            ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => 2, 'configuration' => ['key' => 'demo_url', 'label' => 'Demo link', 'type' => 'url']]);
        }
        $submission = $this->completeActivity($participant, $linkedActivity);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => SubmissionStatus::Submitted, 'field_values' => ['deliverable_url' => 'https://github.com/ana/l4/pull/3', 'demo_url' => 'https://example.com/demo']]);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => SubmissionStatus::Draft, 'field_values' => ['deliverable_url' => 'https://github.com/ana/l4/pull/9']]);
        $draft = ActivitySubmission::factory()->for($participant, 'participant')->for($draftActivity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($draft, 'submission')->create(['field_values' => ['deliverable_url' => 'https://github.com/ana/l4/pull/4']]);
        $notGitHub = $this->completeActivity($participant, $notGitHubActivity);
        SubmissionVersion::factory()->for($notGitHub, 'submission')->create(['status' => SubmissionStatus::Submitted, 'field_values' => ['deliverable_url' => 'javascript:alert(1)']]);

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'Deliverable links',
                'Activity 1 · '.e($linkedActivity->title),
                'Deliverable link',
                '<a href="https://github.com/ana/l4/pull/3" target="_blank" rel="noopener noreferrer"',
                '>https://github.com/ana/l4/pull/3</a>',
                'Activity 2 · '.e($draftActivity->title),
                'No GitHub link submitted yet.',
                'Activity 3 · '.e($notGitHubActivity->title),
                'No GitHub link submitted yet.',
                'action="'.route('training.coach.gates.update', [$session, $week, $participant, $gate]).'"',
            ], false)
            ->assertDontSee('https://github.com/ana/l4/pull/9')
            ->assertDontSee('https://github.com/ana/l4/pull/4')
            ->assertDontSee('https://example.com/demo')
            ->assertDontSee('javascript:', false);
    }

    public function test_the_checklist_page_shows_the_participants_repository_for_the_session(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $sessionA, 'week' => $week, 'gate' => $gate] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $sessionB = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->assignMentor($coach, $participant, $sessionB);
        $sessionA->participants()->updateExistingPivot($participant->id, ['repository_url' => 'https://github.com/ana/l4-accelerator']);

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$sessionA, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'Participant: '.e($participant->name).'</p>',
                'Repository:',
                '<a href="https://github.com/ana/l4-accelerator" target="_blank" rel="noopener noreferrer"',
                '>https://github.com/ana/l4-accelerator<span class="sr-only"> (opens in a new tab)</span></a>',
                '</header>',
                'Weekly checklist',
            ], false)
            ->assertDontSee('No repository yet');

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$sessionB, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder(['Participant: '.e($participant->name).'</p>', 'Repository:', 'No repository yet', '</header>'], false)
            ->assertDontSee('l4-accelerator');
    }

    public function test_blocked_participant_shows_the_blockers_and_a_missing_video(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'Checklist',
                '0 / 2',
                'Activities',
                '0 / 1 submitted',
                'Video Recording',
                'Missing',
                'Blockers',
                'Activity 1 must have a submitted version.',
                'Required Weekly Video Recording is missing. Choose your video from Google Drive before this week can be marked completed.',
                'Weekly checklist',
                'Deliverable links',
                'No GitHub link submitted yet.',
            ])
            ->assertDontSee('View Video');
    }

    public function test_a_gate_without_a_weekly_video_criterion_shows_the_video_as_not_required_and_no_video_blocker(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($gate, 'gate')->create();
        Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->assignMentor($coach, $participant, $session);

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder([
                'Checklist',
                '0 / 1',
                'Video Recording',
                'Not required',
                'Blockers',
                'Activity 1 must have a submitted version.',
                'Weekly checklist',
            ])
            ->assertDontSee('Required Weekly Video Recording is missing.');
    }

    public function test_met_criteria_count_the_video_criterion_once_its_recording_is_reviewed_as_met(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        [$activityCriterion, $videoCriterion] = $criteria;
        $this->chooseWeeklyVideo($participant, $week);
        $evaluation = GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        GateCriterionEvaluation::factory()->for($evaluation)->for($activityCriterion, 'criterion')->create(['status' => GateCriterionStatus::NotMet]);
        GateCriterionEvaluation::factory()->for($evaluation)->for($videoCriterion, 'criterion')->create(['status' => GateCriterionStatus::Met]);

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder(['>Checklist</span>', '1 / 2', 'Activities'], false);
    }

    public function test_only_the_participants_current_mentor_among_the_session_mentors_opens_the_gate(): void
    {
        [$currentMentor, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $currentMentor);
        $otherMentor = User::factory()->create(['role' => 'coach']);
        $session->mentors()->attach($otherMentor->id);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => User::factory()->create()->id, 'mentor_id' => $otherMentor->id]);
        $previousMentor = User::factory()->create(['role' => 'coach']);
        $session->mentors()->attach($previousMentor->id);
        MentorAssignment::factory()->ended()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $previousMentor->id]);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        $pageUrl = route('training.coach.gates.show', [$session, $week, $participant, $gate]);

        $this->actingAs($otherMentor)->get($pageUrl)->assertForbidden();
        $this->actingAs($previousMentor)->get($pageUrl)->assertForbidden();
        $this->actingAs($currentMentor)->get($pageUrl)->assertOk()->assertSee('Save Gate Evaluation');
    }

    public function test_an_admin_who_mentors_no_one_sees_the_decision_form(): void
    {
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSee('id="gate-review-main-action"', false)
            ->assertSee('action="'.route('training.coach.gates.update', [$session, $week, $participant, $gate]).'" id="gate-review-form"', false);
    }

    public function test_a_rejected_decision_returns_to_the_page_with_the_error(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        [$activityCriterion, $videoCriterion] = $criteria;
        $pageUrl = route('training.coach.gates.show', [$session, $week, $participant, $gate]);

        $content = $this->actingAs($coach)->from($pageUrl)->followingRedirects()
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => [$activityCriterion->id => 'met', $videoCriterion->id => 'not_met'],
                'comments' => 'Completing early.',
            ])
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Tick every checklist item to mark the week completed.')
            ->assertSee('Completing early.')
            ->assertSee('<option value="passed" selected>Completed</option>', false)
            ->getContent();

        $this->assertSame(1, substr_count($content, ' checked'));
    }

    public function test_saving_a_review_stores_each_required_criterion_decision_and_a_left_out_criterion_as_not_met(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 2, mentor: $coach);
        [$metCriterion, $notMetCriterion, $videoCriterion] = $criteria;

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'needs_revision',
                'criteria' => [$metCriterion->id => 'met', $notMetCriterion->id => 'not_met'],
            ])
            ->assertSessionHasNoErrors();

        $evaluation = GateEvaluation::query()->where('user_id', $participant->id)->sole();
        $this->assertSame(GateEvaluationStatus::NeedsRevision, $evaluation->status);
        $criterionEvaluations = $evaluation->criterionEvaluations->keyBy('gate_criterion_id');
        $this->assertCount(3, $criterionEvaluations);
        $this->assertSame(GateCriterionStatus::Met, $criterionEvaluations->get($metCriterion->id)->status);
        $this->assertSame(GateCriterionStatus::NotMet, $criterionEvaluations->get($notMetCriterion->id)->status);
        $this->assertSame(GateCriterionStatus::NotMet, $criterionEvaluations->get($videoCriterion->id)->status);
    }

    public function test_completing_a_week_fails_while_a_checklist_item_is_unticked(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        [$activityCriterion, $videoCriterion] = $criteria;
        $this->completeActivity($participant, $activities->first());
        $this->chooseWeeklyVideo($participant, $week);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => [$activityCriterion->id => 'not_met', $videoCriterion->id => 'met'],
            ])
            ->assertSessionHasErrors(['criteria' => 'Tick every checklist item to mark the week completed.']);
        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => [$videoCriterion->id => 'met'],
            ])
            ->assertSessionHasErrors(['criteria' => 'Tick every checklist item to mark the week completed.']);
        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);
    }

    public function test_every_ticked_item_completes_the_week_while_readiness_still_has_blockers(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $this->chooseWeeklyVideo($participant, $week);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
            ])
            ->assertSessionHasNoErrors();

        $evaluation = GateEvaluation::query()->where('user_id', $participant->id)->sole();
        $this->assertSame(GateEvaluationStatus::Passed, $evaluation->status);
        $this->assertSame([GateCriterionStatus::Met, GateCriterionStatus::Met], $evaluation->criterionEvaluations->pluck('status')->all());
    }

    public function test_a_week_cannot_be_completed_with_the_weekly_video_ticked_before_it_is_chosen(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $videoCriterion = $criteria->last();

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
            ])
            ->assertSessionHasErrors(['criteria.'.$videoCriterion->id => 'The Weekly Video Recording cannot be marked Met until the participant has chosen a video.']);

        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);
    }

    public function test_marking_a_week_incomplete_fails_when_every_checklist_item_is_ticked(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $this->chooseWeeklyVideo($participant, $week);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'needs_revision',
                'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
            ])
            ->assertSessionHasErrors(['criteria' => 'Leave at least one item unticked to mark the week incomplete.']);

        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);
    }

    public function test_an_admin_can_mark_a_week_completed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1);
        $this->chooseWeeklyVideo($participant, $week);

        $this->actingAs($admin)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'passed',
                'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
            ])
            ->assertSessionHasNoErrors();

        $evaluation = GateEvaluation::query()->where('user_id', $participant->id)->sole();
        $this->assertSame(GateEvaluationStatus::Passed, $evaluation->status);
        $this->assertTrue($evaluation->reviewer->is($admin));
    }

    public function test_a_week_one_gate_can_be_reviewed(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, weekNumber: 1, mentor: $coach);
        [$activityCriterion, $videoCriterion] = $criteria;

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $participant, $gate]))
            ->assertOk()
            ->assertSee('WEEK 1 · GATE REVIEW')
            ->assertSee('action="'.route('training.coach.gates.update', [$session, $week, $participant, $gate]).'" id="gate-review-form"', false);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'needs_revision',
                'criteria' => [$activityCriterion->id => 'met', $videoCriterion->id => 'not_met'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(GateEvaluationStatus::NeedsRevision, GateEvaluation::query()->where('user_id', $participant->id)->sole()->status);
    }

    public function test_a_participant_who_is_not_in_the_session_is_not_found(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $outsider = User::factory()->create();
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $outsider->id, 'mentor_id' => $coach->id]);
        $review = [
            'status' => 'needs_revision',
            'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'not_met'])->all(),
        ];

        $this->actingAs($coach)->get(route('training.coach.gates.show', [$session, $week, $outsider, $gate]))->assertNotFound();
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$session, $week, $outsider, $gate]), $review)->assertNotFound();
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$session, $week, $outsider, $gate]), [])->assertNotFound();

        $this->assertDatabaseEmpty('gate_evaluations');
    }

    public function test_a_review_in_one_session_is_not_shown_or_changed_in_another(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $sessionA, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $sessionB = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->assignMentor($coach, $participant, $sessionB);
        $this->chooseWeeklyVideo($participant, $week);
        $everyItemTicked = [
            'status' => 'passed',
            'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'met'])->all(),
            'comments' => 'Completed in session A.',
        ];

        $this->actingAs($coach)->post(route('training.coach.gates.update', [$sessionA, $week, $participant, $gate]), $everyItemTicked)
            ->assertSessionHasNoErrors();

        $content = $this->actingAs($coach)->get(route('training.coach.gates.show', [$sessionB, $week, $participant, $gate]))
            ->assertOk()
            ->assertSeeInOrder(['Video Recording', 'Missing'])
            ->assertSee('action="'.route('training.coach.gates.update', [$sessionB, $week, $participant, $gate]).'"', false)
            ->assertDontSee('Completed in session A.')
            ->getContent();
        $this->assertSame(0, substr_count($content, ' checked'));

        $this->actingAs($coach)->post(route('training.coach.gates.update', [$sessionB, $week, $participant, $gate]), $everyItemTicked)
            ->assertSessionHasErrors(['criteria.'.$criteria->last()->id => 'The Weekly Video Recording cannot be marked Met until the participant has chosen a video.']);
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$sessionB, $week, $participant, $gate]), [
            'status' => 'needs_revision',
            'criteria' => [$criteria->first()->id => 'met'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(GateEvaluationStatus::Passed, GateEvaluation::query()->whereBelongsTo($sessionA)->sole()->status);
        $this->assertSame(GateEvaluationStatus::NeedsRevision, GateEvaluation::query()->whereBelongsTo($sessionB)->sole()->status);
    }

    #[DataProvider('statusesThatAreNotDecisions')]
    public function test_a_gate_decision_must_be_completed_or_incomplete(string $status): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => $status,
                'criteria' => $criteria->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => 'not_met'])->all(),
            ])
            ->assertSessionHasErrors(['status' => 'The selected status is invalid.']);

        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesThatAreNotDecisions(): array
    {
        return [
            'not ready' => ['not_ready'],
            'ready for review' => ['ready_for_review'],
            'awaiting review' => ['awaiting_review'],
            'failed' => ['failed'],
            'unknown' => ['approved'],
        ];
    }

    public function test_a_criterion_decision_must_be_met_or_not_met(): void
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria] = $this->gateFixture($participant, activityCount: 1, extraCriteriaCount: 1, mentor: $coach);
        $criterionId = $criteria->first()->id;

        $this->actingAs($coach)
            ->post(route('training.coach.gates.update', [$session, $week, $participant, $gate]), [
                'status' => 'needs_revision',
                'criteria' => [$criterionId => 'passed'],
            ])
            ->assertSessionHasErrors(['criteria.'.$criterionId => "The selected criteria.{$criterionId} is invalid."]);

        $this->assertDatabaseMissing('gate_evaluations', ['user_id' => $participant->id]);
    }

    public function test_gate_review_query_count_does_not_grow_with_activities_and_criteria(): void
    {
        $this->assertSame($this->gateReviewQueryCount(1), $this->gateReviewQueryCount(3));
    }

    /**
     * Give a new participant a Week 2 Gate with the given number of activities (each submitted with a GitHub link) and
     * extra required criteria, and count the queries of the assigned coach's page request after a warm-up request.
     */
    private function gateReviewQueryCount(int $rows): int
    {
        [$coach, $participant] = $this->coachAndParticipant();
        ['session' => $session, 'week' => $week, 'gate' => $gate, 'activities' => $activities] = $this->gateFixture($participant, activityCount: $rows, extraCriteriaCount: $rows, mentor: $coach);
        foreach ($activities as $activity) {
            ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'configuration' => ['key' => 'deliverable_url', 'label' => 'Deliverable link', 'type' => 'github_url']]);
            SubmissionVersion::factory()->for($this->completeActivity($participant, $activity), 'submission')->create(['status' => SubmissionStatus::Submitted, 'field_values' => ['deliverable_url' => "https://github.com/ana/l4/pull/{$activity->id}"]]);
        }
        $this->chooseWeeklyVideo($participant, $week);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);
        $url = route('training.coach.gates.show', [$session, $week, $participant, $gate]);

        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee("{$rows} / {$rows} submitted");
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A Gate of the given week (Week 2 by default) with a required weekly video criterion, the given number of other
     * required criteria, one optional criterion and the given number of active activities, and an active session of
     * the week's program with the participant in it (and the mentor, when given, as the participant's mentor there).
     *
     * @return array{session: TrainingSession, week: TrainingWeek, gate: TrainingGate, criteria: Collection<int, GateCriterion>, activities: Collection<int, Activity>}
     */
    private function gateFixture(User $participant, int $activityCount, int $extraCriteriaCount, int $weekNumber = 2, ?User $mentor = null): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        if ($mentor !== null) {
            $this->assignMentor($mentor, $participant, $session);
        }
        $gate = TrainingGate::factory()->for($week, 'week')->create();
        $criteria = collect(range(1, $extraCriteriaCount))
            ->map(fn (int $position): GateCriterion => GateCriterion::factory()->for($gate, 'gate')->create(['position' => $position]))
            ->push(GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video', 'title' => 'Required Weekly Video Recording', 'position' => 10]));
        GateCriterion::factory()->for($gate, 'gate')->create(['title' => 'Optional stretch goal', 'is_required' => false, 'position' => 11]);
        $activities = collect(range(1, $activityCount))
            ->map(fn (int $position): Activity => Activity::factory()->for($week, 'week')->create(['position' => $position]));

        return ['session' => $session, 'week' => $week, 'gate' => $gate, 'criteria' => $criteria, 'activities' => $activities];
    }

    private function completeActivity(User $participant, Activity $activity): ActivitySubmission
    {
        return ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
    }

    private function chooseWeeklyVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();
    }

    /**
     * A coach and a participant; pass the coach to gateFixture() as `mentor` to make them the participant's mentor.
     *
     * @return array{User, User}
     */
    private function coachAndParticipant(): array
    {
        return [User::factory()->create(['role' => 'coach']), User::factory()->create()];
    }

    /**
     * Make the coach a mentor of the session and the participant's current mentor there.
     */
    private function assignMentor(User $coach, User $participant, TrainingSession $session): void
    {
        $session->mentors()->attach($coach->id);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
    }
}
