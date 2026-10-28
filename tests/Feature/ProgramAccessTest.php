<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ProgramEnrollmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_in_the_curriculum_opens_its_week_and_activity_pages(): void
    {
        ['program' => $program, 'week' => $week, 'activity' => $activity] = $this->programFixture();
        $member = $this->checkedInParticipant();
        $program->participants()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->get(route('training.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSee('href="'.route('training.activities.show', [$program, $week, $activity]).'"', false);
        $this->actingAs($member)->get(route('training.activities.show', [$program, $week, $activity]))
            ->assertOk()
            ->assertSee($activity->title);
    }

    public function test_a_participant_who_is_not_in_the_curriculum_is_forbidden_from_the_week_and_activity_pages(): void
    {
        ['program' => $program, 'week' => $week, 'activity' => $activity] = $this->programFixture();
        $outsider = $this->checkedInParticipant();
        Program::factory()->withWeeks(1)->withParticipants($outsider)->create();

        $this->actingAs($outsider)->get(route('training.weeks.show', [$program, $week]))->assertForbidden();
        $this->actingAs($outsider)->get(route('training.activities.show', [$program, $week, $activity]))->assertForbidden();
    }

    public function test_a_participant_removed_from_the_curriculum_is_forbidden_from_the_week_and_activity_pages(): void
    {
        ['program' => $program, 'week' => $week, 'activity' => $activity] = $this->programFixture();
        $participant = $this->checkedInParticipant();
        $program->participants()->attach($participant, ['joined_at' => now()]);
        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $week]))->assertOk();

        app(ProgramEnrollmentService::class)->removeParticipant($program, $participant, User::factory()->create(['role' => 'admin']));

        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $week]))->assertForbidden();
        $this->actingAs($participant)->get(route('training.activities.show', [$program, $week, $activity]))->assertForbidden();
    }

    public function test_a_week_of_another_program_is_not_found_in_the_curriculum(): void
    {
        ['program' => $program] = $this->programFixture();
        $member = $this->checkedInParticipant();
        $program->participants()->attach($member, ['joined_at' => now()]);
        $foreignWeek = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $foreignActivity = Activity::factory()->for($foreignWeek, 'week')->create();

        $this->actingAs($member)->get(route('training.weeks.show', [$program, $foreignWeek]))->assertNotFound();
        $this->actingAs($member)->get(route('training.activities.show', [$program, $foreignWeek, $foreignActivity]))->assertNotFound();
    }

    public function test_an_inactive_week_of_the_curriculum_is_not_found(): void
    {
        ['program' => $program] = $this->programFixture();
        $member = $this->checkedInParticipant();
        $program->participants()->attach($member, ['joined_at' => now()]);
        $inactiveWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2, 'is_active' => false]);
        $inactiveWeekActivity = Activity::factory()->for($inactiveWeek, 'week')->create();

        $this->actingAs($member)->get(route('training.weeks.show', [$program, $inactiveWeek]))->assertNotFound();
        $this->actingAs($member)->get(route('training.activities.show', [$program, $inactiveWeek, $inactiveWeekActivity]))->assertNotFound();
    }

    public function test_an_unscheduled_week_is_not_open_to_a_participant(): void
    {
        ['program' => $program] = $this->programFixture();
        $member = $this->checkedInParticipant();
        $program->participants()->attach($member, ['joined_at' => now()]);
        $unscheduledWeek = TrainingWeek::factory()->for($program)->unscheduled()->create(['week_number' => 2, 'position' => 2]);
        $activity = Activity::factory()->for($unscheduledWeek, 'week')->create();

        $this->actingAs($member)->get(route('training.weeks.show', [$program, $unscheduledWeek]))->assertForbidden()->assertSee('Not scheduled yet');
        $this->actingAs($member)->get(route('training.activities.show', [$program, $unscheduledWeek, $activity]))->assertForbidden()->assertSee('Not scheduled yet');
        $this->actingAs($member)->get(route('training.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['id="program-tab-week-2"', 'Not scheduled yet'], false);
    }

    public function test_a_week_opens_on_its_own_start_date(): void
    {
        $this->travelTo('2026-11-19 09:00:00');
        $participant = $this->checkedInParticipant();
        $program = Program::factory()->withParticipants($participant)->create();
        TrainingWeek::factory()->for($program)->opensOn(Carbon::parse('2026-11-09'))->create(['week_number' => 1, 'position' => 1]);
        $weekTwo = TrainingWeek::factory()->for($program)->opensOn(Carbon::parse('2026-11-20'))->create(['week_number' => 2, 'position' => 2]);

        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $weekTwo]))->assertForbidden()->assertSee('Opens on Fri 20 Nov');

        $this->travelTo('2026-11-20 00:01:00');

        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $weekTwo]))->assertOk();
    }

    public function test_admins_and_the_curriculums_mentors_open_the_week_and_activity_pages_of_a_curriculum_they_are_not_in(): void
    {
        ['program' => $program, 'week' => $week, 'activity' => $activity] = $this->programFixture();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program->mentors()->attach($mentor->id);
        $week->update(['starts_on' => today()->addWeek(), 'ends_on' => today()->addWeek()->addDays(6)]);

        foreach ([User::factory()->create(['role' => 'admin']), $mentor] as $user) {
            $this->actingAs($user)->get(route('training.weeks.show', [$program, $week]))->assertOk();
            $this->actingAs($user)->get(route('training.activities.show', [$program, $week, $activity]))->assertOk();
        }
    }

    public function test_a_coach_who_is_not_a_mentor_of_the_curriculum_cannot_open_its_week_and_activity_pages(): void
    {
        ['program' => $program, 'week' => $week, 'activity' => $activity] = $this->programFixture();
        $otherProgramMentor = User::factory()->create(['role' => 'coach']);
        Program::factory()->withWeeks(1)->withMentors($otherProgramMentor)->create();

        $this->actingAs($otherProgramMentor)->get(route('training.weeks.show', [$program, $week]))->assertForbidden();
        $this->actingAs($otherProgramMentor)->get(route('training.activities.show', [$program, $week, $activity]))->assertForbidden();
    }

    #[DataProvider('closedWeekCases')]
    public function test_a_participant_is_forbidden_from_the_week_activity_and_submission_pages_of_a_closed_week_with_its_message(string $today, ?GateEvaluationStatus $weekOneReview, bool $weekOneWorkAfterReview, string $message): void
    {
        $this->travelTo($today);
        $participant = $this->checkedInParticipant();
        $program = Program::factory()->withParticipants($participant)->create();
        $weekOne = TrainingWeek::factory()->for($program)->opensOn(Carbon::parse('2026-11-09'))->create(['week_number' => 1, 'position' => 1]);
        $weekTwo = TrainingWeek::factory()->for($program)->opensOn(Carbon::parse('2026-11-16'))->create(['week_number' => 2, 'position' => 2]);
        $weekOneActivity = Activity::factory()->for($weekOne, 'week')->create();
        $weekTwoActivity = Activity::factory()->for($weekTwo, 'week')->create();
        $gate = TrainingGate::factory()->for($weekOne, 'week')->create();
        if ($weekOneReview) {
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $weekOneReview, 'evaluated_at' => '2026-11-14 10:00:00']);
        }
        if ($weekOneWorkAfterReview) {
            SubmissionVersion::factory()
                ->for(ActivitySubmission::factory()->for($weekOneActivity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Submitted]), 'submission')
                ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => '2026-11-15 08:00:00']);
        }
        $handedIn = SubmissionVersion::factory()
            ->for(ActivitySubmission::factory()->for($weekTwoActivity)->for($participant, 'participant')->create(['status' => SubmissionStatus::Submitted]), 'submission')
            ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);

        foreach ([
            route('training.weeks.show', [$program, $weekTwo]),
            route('training.activities.show', [$program, $weekTwo, $weekTwoActivity]),
            route('training.submission.edit', [$program, $weekTwo, $weekTwoActivity]),
            route('training.submission.show', [$program, $weekTwo, $weekTwoActivity, $handedIn]),
        ] as $url) {
            $this->actingAs($participant)->get($url)->assertForbidden()->assertSee($message);
        }
        $this->actingAs($participant)->get(route('training.weeks.show', [$program, $weekOne]))->assertOk();
        $this->actingAs($participant)->get(route('training.activities.show', [$program, $weekOne, $weekOneActivity]))->assertOk();
    }

    /**
     * The reasons Week 2 (Mon 16 Nov 2026) of a curriculum whose Week 1 starts on Mon 9 Nov is closed: before its date,
     * after its date while Week 1 has an incomplete review, and the same once Week 1 work was handed in after that
     * review.
     *
     * @return array<string, array{string, ?GateEvaluationStatus, bool, string}>
     */
    public static function closedWeekCases(): array
    {
        return [
            'not opened yet' => ['2026-11-15 09:00:00', null, false, 'Opens on Mon 16 Nov'],
            'locked by an incomplete review' => ['2026-11-16 09:00:00', GateEvaluationStatus::NeedsRevision, false, 'Complete your Week 1 deliverables to unlock'],
            'waiting for the mentor to verify' => ['2026-11-16 09:00:00', GateEvaluationStatus::NeedsRevision, true, 'Waiting for your mentor to verify Week 1'],
        ];
    }

    public function test_only_a_participant_in_the_curriculum_opens_its_curriculum_page(): void
    {
        ['program' => $program] = $this->programFixture();
        $member = User::factory()->create();
        $program->participants()->attach($member, ['joined_at' => now()]);
        $outsider = User::factory()->create();
        Program::factory()->withWeeks(1)->withParticipants($outsider)->create();

        $this->actingAs($member)->get(route('training.programs.show', $program))->assertOk()->assertSee($program->name);
        $this->actingAs($outsider)->get(route('training.programs.show', $program))->assertForbidden();

        app(ProgramEnrollmentService::class)->removeParticipant($program, $member, User::factory()->create(['role' => 'admin']));

        $this->actingAs($member)->get(route('training.programs.show', $program))->assertForbidden();
    }

    public function test_admins_and_the_curriculums_mentors_are_forbidden_from_the_participant_curriculum_page(): void
    {
        ['program' => $program] = $this->programFixture();
        $mentor = User::factory()->create(['role' => 'coach']);
        $program->mentors()->attach($mentor->id);

        foreach ([User::factory()->create(['role' => 'admin']), $mentor] as $user) {
            $this->actingAs($user)->get(route('training.programs.show', $program))->assertForbidden();
        }
    }

    /**
     * A curriculum whose Week 1, open today, has one activity.
     *
     * @return array{program: Program, week: TrainingWeek, activity: Activity}
     */
    private function programFixture(): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Set up the repository']);

        return [
            'program' => $week->program,
            'week' => $week,
            'activity' => $activity,
        ];
    }

    /**
     * A participant who has checked in, so the current week's activity page opens without the attendance redirect.
     */
    private function checkedInParticipant(): User
    {
        $participant = User::factory()->create();

        return $participant;
    }
}
