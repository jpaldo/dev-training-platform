<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourPartnerFeedback;
use App\Models\WeekFourPeerInterviewWaiver;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeekFourWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();

        return [$program->weeks()->where('week_number', 3)->firstOrFail(), $program->weeks()->where('week_number', 4)->firstOrFail()];
    }

    private function unlockWeek(User $participant, $week): void
    {
        $week->program->weeks()
            ->where('position', '<', $week->position)
            ->with('gates')
            ->get()
            ->each(function ($previous) use ($participant): void {
                $previous->gates->each(function ($gate) use ($participant): void {
                    GateEvaluation::create([
                        'training_gate_id' => $gate->id,
                        'user_id' => $participant->id,
                        'status' => 'passed',
                        'evaluated_at' => now(),
                    ]);
                });
            });
    }

    public function test_week_four_official_activities_are_available_for_both_paths(): void
    {
        [$week3, $week4] = $this->fixture();
        foreach (['manual', 'automation'] as $path) {
            $participant = User::factory()->create(['training_path' => $path]);
            $this->unlockWeek($participant, $week4);
            $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
            $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertOk();
            $this->assertCount(3, $week4->activities);
            foreach ($week4->activities as $activity) {
                $this->actingAs($participant)->get(route('training.activities.show', [$week4, $activity]))->assertOk();
            }
        }
    }

    public function test_week_four_gate_requires_packet_and_coach_pass(): void
    {
        [$week3, $week4] = $this->fixture();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertForbidden();
        $this->unlockWeek($participant, $week4);
        foreach ($week4->activities as $activity) {
            $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
            $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => [], 'submitted_at' => now()]);
            $evidence = $activity->requirements()->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->firstWhere('is_required', true);
            SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_requirement_id' => $evidence->id, 'evidence_type' => $evidence->evidence_type, 'title' => 'Packet evidence', 'disk' => 'local', 'path' => 'proof/item']);
        }
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $week4->activities->get(1)->id, 'logged_on' => now()->toDateString(), 'content' => 'Mock interview logged']);
        $gate = $week4->gates->first();
        $criteria = $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all();
        $this->actingAs($coach)->post(route('training.coach.gates.update', [$week4, $participant, $gate]), ['status' => 'passed', 'criteria' => $criteria])->assertRedirect();
        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertOk();
    }

    public function test_participant_cannot_self_pass_week_four_gate(): void
    {
        [$week3, $week4] = $this->fixture();
        $participant = User::factory()->create();
        $this->unlockWeek($participant, $week4);
        $gate = $week4->gates->first();
        $this->actingAs($participant)->post(route('training.coach.gates.update', [$week4, $participant, $gate]), ['status' => 'passed', 'criteria' => $gate->criteria->pluck('id')->mapWithKeys(fn ($id) => [$id => 'met'])->all()])->assertForbidden();
    }

    public function test_mock_interview_uses_registered_partner_and_requires_two_feedback_answers(): void
    {
        [$week3, $week4] = $this->fixture();
        $participant = User::factory()->create(['name' => 'Participant A', 'training_path' => 'manual']);
        $partner = User::factory()->create(['name' => 'Participant B', 'role' => 'participant', 'training_path' => 'automation']);
        $this->unlockWeek($participant, $week4);
        $this->unlockWeek($partner, $week4);
        WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $partner->id), 'participant_two_id' => max($participant->id, $partner->id), 'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => 'active']);
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()]);
        AttendanceSession::create(['user_id' => $partner->id, 'check_in_at' => now()]);

        $activity = $week4->activities()->where('position', 2)->firstOrFail();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week4, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['YOUR MOCK INTERVIEW PARTNER</h3>', '<strong>Participant B</strong>'], false)
            ->assertDontSee('<strong>Participant A</strong>', false);

        $base = [
            'field_values' => [
                'interview_date' => now()->toDateString(),
                'question_1_answer' => 'Answer one',
                'question_2_answer' => 'Answer two',
                'question_3_answer' => 'Answer three',
            ],
        ];
        $this->actingAs($participant)->post(route('training.submission.draft', [$week4, $activity]), $base)->assertRedirect();
        $this->actingAs($partner)->post(route('training.submission.draft', [$week4, $activity]), $base)->assertRedirect();
        foreach ([1, 2] as $question) {
            $this->actingAs($participant)->post(route('training.week-four.partner-feedback'), ['question_number' => $question, 'content' => 'Feedback from A'])->assertRedirect();
            $this->actingAs($partner)->post(route('training.week-four.partner-feedback'), ['question_number' => $question, 'content' => 'Feedback from B'])->assertRedirect();
        }
        $submission = ActivitySubmission::query()->where('user_id', $participant->id)->where('activity_id', $activity->id)->firstOrFail();
        $version = $submission->versions()->latest('version_number')->firstOrFail();
        $evidenceRequirement = $activity->requirements()->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->firstWhere('is_required', true);
        SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_requirement_id' => $evidenceRequirement->id, 'evidence_type' => 'text', 'title' => 'Interview notes', 'content' => 'Interview evidence']);
        $this->actingAs($participant)->post(route('training.submission.submit', [$week4, $activity, $version]))->assertRedirect();
        $version->refresh();
        $this->assertSame('Participant B', $version->field_values['partner_snapshot']['display_name']);
        $this->assertSame('automation', $version->field_values['partner_snapshot']['training_path']);
        $this->actingAs($participant)->get(route('training.submission.show', [$week4, $activity, $version]))
            ->assertOk()
            ->assertSee('Interview Partner')
            ->assertSee('Participant B')
            ->assertDontSee('partner_user_id');
    }

    public function test_participant_mock_interview_shows_peer_slots_without_official_questionnaire(): void
    {
        [, $week4] = $this->fixture();
        $participant = User::factory()->create(['name' => 'Participant A']);
        $partner = User::factory()->create(['name' => 'Participant B', 'role' => 'participant']);
        $this->unlockWeek($participant, $week4);
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()]);
        AttendanceSession::create(['user_id' => $partner->id, 'check_in_at' => now()]);
        WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $partner->id), 'participant_two_id' => max($participant->id, $partner->id), 'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => 'active']);
        $activity = $week4->activities()->where('position', 2)->firstOrFail();

        $response = $this->actingAs($participant)->get(route('training.submission.edit', [$week4, $activity]))->assertOk();
        $response->assertSee('ABOUT YOU')->assertSee('Interview Guidance')->assertSee('Q1')->assertSee('Q15');
        $response->assertDontSee('AI Usage Frequency')->assertDontSee('How many times do you use AI?')->assertDontSee('Failed AI Attempt / Learning');
    }

    public function test_mock_interview_partners_show_whether_they_have_unlocked_week_four(): void
    {
        [, $week4] = $this->fixture();
        $participant = User::factory()->create();
        $unlockedPartner = User::factory()->create(['name' => 'Unlocked Partner']);
        $lockedPartner = User::factory()->create(['name' => 'Locked Partner']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->unlockWeek($participant, $week4);
        $this->unlockWeek($unlockedPartner, $week4);
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()]);
        foreach ([$unlockedPartner, $lockedPartner] as $partner) {
            WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $partner->id), 'participant_two_id' => max($participant->id, $partner->id), 'assigned_by_id' => $admin->id, 'status' => 'active']);
        }
        $activity = $week4->activities()->where('position', 2)->firstOrFail();

        $this->actingAs($participant)->get(route('training.submission.edit', [$week4, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['Unlocked Partner', 'WEEK 4: UNLOCKED', 'Locked Partner', 'WEEK 4: LOCKED', 'Your partner has not unlocked Week 4 yet.']);
    }

    public function test_coach_can_view_official_questionnaire_but_participant_cannot(): void
    {
        [, $week4] = $this->fixture();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($participant)->get(route('coach.week-four.questionnaire'))->assertForbidden();
        $this->actingAs($coach)->get(route('coach.week-four.questionnaire'))->assertOk()->assertSee('AI Usage Frequency')->assertSee('How many times do you use AI?');
        $this->actingAs($admin)->get(route('coach.week-four.questionnaire'))->assertOk()->assertSee('AI Usage Frequency');
    }

    public function test_feedback_author_and_recipient_are_derived_from_the_authenticated_pair(): void
    {
        [, $week4] = $this->fixture();
        $participant = User::factory()->create(['name' => 'Participant A']);
        $partner = User::factory()->create(['name' => 'Participant B']);
        $unrelated = User::factory()->create(['name' => 'Participant C']);
        WeekFourInterviewPair::create([
            'participant_one_id' => min($participant->id, $partner->id),
            'participant_two_id' => max($participant->id, $partner->id),
            'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id,
            'status' => 'active',
        ]);

        $this->actingAs($partner)->post(route('training.week-four.partner-feedback'), [
            'question_number' => 1,
            'content' => 'Original partner feedback',
        ])->assertRedirect();

        $this->actingAs($participant)->post(route('training.week-four.partner-feedback'), [
            'question_number' => 1,
            'content' => 'Forged replacement',
            'author_id' => $partner->id,
            'recipient_id' => $unrelated->id,
            'feedback_id' => WeekFourPartnerFeedback::query()->firstOrFail()->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('week_four_partner_feedback', [
            'author_id' => $partner->id,
            'recipient_id' => $participant->id,
            'content' => 'Original partner feedback',
        ]);
        $this->assertDatabaseHas('week_four_partner_feedback', [
            'author_id' => $participant->id,
            'recipient_id' => $partner->id,
            'content' => 'Forged replacement',
        ]);
        $this->actingAs($participant)->post(route('coach.week-four.pairs.assign'), [
            'participant_one_id' => $participant->id,
            'participant_two_id' => $unrelated->id,
        ])->assertForbidden();
    }

    public function test_partners_author_their_own_questions_and_answers_are_scoped_to_the_partner(): void
    {
        [, $week4] = $this->fixture();
        $a = User::factory()->create(['name' => 'Participant A']);
        $b = User::factory()->create(['name' => 'Participant B']);
        $this->unlockWeek($a, $week4);
        $this->unlockWeek($b, $week4);
        AttendanceSession::create(['user_id' => $a->id, 'check_in_at' => now()]);
        AttendanceSession::create(['user_id' => $b->id, 'check_in_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        WeekFourInterviewPair::create(['participant_one_id' => min($a->id, $b->id), 'participant_two_id' => max($a->id, $b->id), 'assigned_by_id' => $admin->id, 'status' => 'active']);
        $activity = $week4->activities()->where('position', 2)->firstOrFail();
        $aQuestions = array_fill(1, 15, 'A practice question');
        $bQuestions = array_fill(1, 15, 'B practice question');
        $this->actingAs($a)->post(route('training.submission.draft', [$week4, $activity]), ['field_values' => ['about_you' => 'A', 'interview_date' => now()->toDateString(), 'practice_questions' => $aQuestions]])->assertRedirect();
        $this->actingAs($b)->post(route('training.submission.draft', [$week4, $activity]), ['field_values' => ['about_you' => 'B', 'interview_date' => now()->toDateString(), 'practice_questions' => $bQuestions]])->assertRedirect();
        $this->actingAs($b)->post(route('training.submission.draft', [$week4, $activity]), ['field_values' => ['practice_answers' => array_fill(1, 15, 'B answer')]])->assertRedirect();
        $this->actingAs($a)->get(route('training.submission.edit', [$week4, $activity]))->assertOk()->assertSee('B practice question')->assertDontSee('AI Usage Frequency');
        $this->actingAs($a)->post(route('training.submission.draft', [$week4, $activity]), ['field_values' => ['practice_answers' => array_fill(1, 15, 'A answer')]])->assertRedirect();
        $this->actingAs($b)->post(route('training.submission.draft', [$week4, $activity]), ['field_values' => ['practice_questions' => array_replace($bQuestions, [1 => 'Changed after answer'])]])->assertSessionHasErrors('field_values.practice_questions.1');
    }

    public function test_participant_can_have_multiple_distinct_active_partners_without_replacing_the_first(): void
    {
        [$week3] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $a = User::factory()->create(['role' => 'participant']);
        $b = User::factory()->create(['role' => 'participant']);
        $c = User::factory()->create(['role' => 'participant']);
        $this->actingAs($admin)->post(route('coach.week-four.pairs.assign'), ['participant_one_id' => $a->id, 'participant_two_id' => $b->id])->assertRedirect();
        $this->actingAs($admin)->post(route('coach.week-four.pairs.assign'), ['participant_one_id' => $a->id, 'participant_two_id' => $c->id])->assertRedirect();

        $this->assertCount(2, WeekFourInterviewPair::forParticipantMany($a->id));
        $this->assertCount(1, WeekFourInterviewPair::forParticipantMany($b->id));
        $this->assertCount(1, WeekFourInterviewPair::forParticipantMany($c->id));
    }

    public function test_coach_can_unpair_their_participants_and_the_pair_is_deactivated(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $a = User::factory()->create(['role' => 'participant']);
        $b = User::factory()->create(['role' => 'participant']);
        $coach->assignedParticipants()->attach([$a->id, $b->id]);
        $pair = WeekFourInterviewPair::create(['participant_one_id' => min($a->id, $b->id), 'participant_two_id' => max($a->id, $b->id), 'assigned_by_id' => $coach->id, 'status' => 'active']);

        $this->actingAs($coach)
            ->from(route('coach.participants'))
            ->post(route('coach.week-four.pairs.unpair', $pair))
            ->assertRedirect(route('coach.participants'))
            ->assertSessionHas('status', 'The Week 4 interview pairing was safely deactivated.');

        $this->assertSame('inactive', $pair->fresh()->status);
    }

    public function test_coach_cannot_unpair_participants_they_do_not_manage(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $a = User::factory()->create(['role' => 'participant']);
        $b = User::factory()->create(['role' => 'participant']);
        $pair = WeekFourInterviewPair::create(['participant_one_id' => min($a->id, $b->id), 'participant_two_id' => max($a->id, $b->id), 'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => 'active']);

        $this->actingAs($coach)->post(route('coach.week-four.pairs.unpair', $pair))->assertForbidden();

        $this->assertSame('active', $pair->fresh()->status);
    }

    public function test_peer_waiver_is_audited_and_can_be_revoked_before_reliance(): void
    {
        [, $week4] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        $activity = $week4->activities()->where('position', 2)->firstOrFail();
        $this->actingAs($admin)->post(route('coach.week-four.peer-waiver.store', $participant), ['reason' => 'No suitable partner'])->assertRedirect();
        $this->assertDatabaseHas('week_four_peer_interview_waivers', ['participant_id' => $participant->id, 'authorized_by_id' => $admin->id, 'reason' => 'No suitable partner']);
        $this->actingAs($admin)->post(route('coach.week-four.peer-waiver.revoke', $participant))->assertRedirect();
        $this->assertNotNull(WeekFourPeerInterviewWaiver::query()->where('participant_id', $participant->id)->firstOrFail()->revoked_at);
    }

    public function test_peer_waiver_for_non_participant_is_not_found_even_with_invalid_reason(): void
    {
        $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($admin)->post(route('coach.week-four.peer-waiver.store', $coach), ['reason' => str_repeat('a', 1001)])->assertNotFound();
        $this->assertDatabaseMissing('week_four_peer_interview_waivers', ['participant_id' => $coach->id]);
    }
}
