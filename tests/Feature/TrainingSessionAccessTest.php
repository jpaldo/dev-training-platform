<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TrainingSessionAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_in_the_session_opens_its_week_and_activity_pages(): void
    {
        ['session' => $session, 'week' => $week, 'activity' => $activity] = $this->sessionFixture();
        $member = $this->checkedInParticipant();
        $session->participants()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->get(route('training.weeks.show', [$session, $week]))
            ->assertOk()
            ->assertSee('href="'.route('training.activities.show', [$session, $week, $activity]).'"', false);
        $this->actingAs($member)->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee($activity->title);
    }

    public function test_a_participant_in_another_session_of_the_program_is_forbidden_from_the_week_and_activity_pages(): void
    {
        ['session' => $session, 'week' => $week, 'activity' => $activity] = $this->sessionFixture();
        $outsider = $this->checkedInParticipant();
        TrainingSession::factory()->forWeek($week)->withParticipants($outsider)->create();

        $this->actingAs($outsider)->get(route('training.weeks.show', [$session, $week]))->assertForbidden();
        $this->actingAs($outsider)->get(route('training.activities.show', [$session, $week, $activity]))->assertForbidden();
    }

    public function test_a_participant_removed_from_the_session_is_forbidden_from_the_week_and_activity_pages(): void
    {
        ['session' => $session, 'week' => $week, 'activity' => $activity] = $this->sessionFixture();
        $participant = $this->checkedInParticipant();
        $session->participants()->attach($participant, ['joined_at' => now()]);
        $this->actingAs($participant)->get(route('training.weeks.show', [$session, $week]))->assertOk();

        app(TrainingSessionService::class)->removeParticipant($session, $participant, User::factory()->create(['role' => 'admin']));

        $this->actingAs($participant)->get(route('training.weeks.show', [$session, $week]))->assertForbidden();
        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))->assertForbidden();
    }

    public function test_a_week_of_another_program_is_not_found_in_the_session(): void
    {
        ['session' => $session] = $this->sessionFixture();
        $member = $this->checkedInParticipant();
        $session->participants()->attach($member, ['joined_at' => now()]);
        $foreignWeek = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $foreignActivity = Activity::factory()->for($foreignWeek, 'week')->create();

        $this->actingAs($member)->get(route('training.weeks.show', [$session, $foreignWeek]))->assertNotFound();
        $this->actingAs($member)->get(route('training.activities.show', [$session, $foreignWeek, $foreignActivity]))->assertNotFound();
    }

    public function test_a_week_beyond_the_session_length_is_not_found(): void
    {
        ['session' => $session, 'week' => $week] = $this->sessionFixture();
        $member = $this->checkedInParticipant();
        $session->participants()->attach($member, ['joined_at' => now()]);
        $laterWeek = TrainingWeek::factory()->for($week->program)->create(['week_number' => $session->weeks_count + 1, 'position' => $session->weeks_count + 1]);
        $laterActivity = Activity::factory()->for($laterWeek, 'week')->create();

        $this->actingAs($member)->get(route('training.weeks.show', [$session, $laterWeek]))->assertNotFound();
        $this->actingAs($member)->get(route('training.activities.show', [$session, $laterWeek, $laterActivity]))->assertNotFound();
    }

    public function test_admins_and_the_sessions_mentors_open_the_week_and_activity_pages_of_a_session_they_are_not_in(): void
    {
        ['session' => $session, 'week' => $week, 'activity' => $activity] = $this->sessionFixture();
        $mentor = User::factory()->create(['role' => 'coach']);
        $session->mentors()->attach($mentor->id);

        foreach ([User::factory()->create(['role' => 'admin']), $mentor] as $user) {
            $this->actingAs($user)->get(route('training.weeks.show', [$session, $week]))->assertOk();
            $this->actingAs($user)->get(route('training.activities.show', [$session, $week, $activity]))->assertOk();
        }
    }

    public function test_a_coach_who_is_not_a_mentor_of_the_session_cannot_open_its_week_and_activity_pages(): void
    {
        ['session' => $session, 'week' => $week, 'activity' => $activity] = $this->sessionFixture();
        $otherSessionMentor = User::factory()->create(['role' => 'coach']);
        TrainingSession::factory()->forWeek($week)->withMentors($otherSessionMentor)->create();

        $this->actingAs($otherSessionMentor)->get(route('training.weeks.show', [$session, $week]))->assertForbidden();
        $this->actingAs($otherSessionMentor)->get(route('training.activities.show', [$session, $week, $activity]))->assertForbidden();
    }

    public function test_only_a_participant_in_the_session_opens_its_session_page(): void
    {
        ['session' => $session, 'week' => $week] = $this->sessionFixture();
        $member = User::factory()->create();
        $session->participants()->attach($member, ['joined_at' => now()]);
        $outsider = User::factory()->create();
        TrainingSession::factory()->forWeek($week)->withParticipants($outsider)->create();

        $this->actingAs($member)->get(route('training.sessions.show', $session))->assertOk()->assertSee($session->title);
        $this->actingAs($outsider)->get(route('training.sessions.show', $session))->assertForbidden();

        app(TrainingSessionService::class)->removeParticipant($session, $member, User::factory()->create(['role' => 'admin']));

        $this->actingAs($member)->get(route('training.sessions.show', $session))->assertForbidden();
    }

    public function test_admins_and_coaches_are_forbidden_from_the_participant_session_page(): void
    {
        ['session' => $session] = $this->sessionFixture();

        foreach (['admin', 'coach'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('training.sessions.show', $session))->assertForbidden();
        }
    }

    /**
     * A 4-week session of a program whose Week 1 has one activity.
     *
     * @return array{session: TrainingSession, week: TrainingWeek, activity: Activity}
     */
    private function sessionFixture(): array
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Set up the repository']);

        return [
            'session' => TrainingSession::factory()->forWeek($week)->create(),
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
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return $participant;
    }
}
