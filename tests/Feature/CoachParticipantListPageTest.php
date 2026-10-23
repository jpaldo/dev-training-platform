<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\MentorAssignment;
use App\Models\TrainingSession;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoachParticipantListPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_mentor_sees_one_row_per_session_where_they_currently_mentor_the_participant(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(15, 0));
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $otherCoach = User::factory()->create(['role' => 'coach', 'name' => 'Other Mentor']);
        $ada = User::factory()->create(['name' => 'Ada Assigned', 'employee_id' => 'EMP-0001', 'drive_link' => 'https://drive.google.com/drive/folders/ada']);
        $una = User::factory()->create(['name' => 'Una Unassigned']);
        $nev = User::factory()->create(['name' => 'Nev Never']);
        $session = TrainingSession::factory()->create(['title' => 'L4 November', 'starts_on' => '2026-11-09', 'ends_on' => '2026-12-06']);
        $otherSession = TrainingSession::factory()->create(['title' => 'L4 October']);
        $this->mentor($session, $coach, $ada);
        $this->mentor($session, $otherCoach, $una);
        $session->participants()->attach($nev, ['joined_at' => now()]);
        $this->mentor($otherSession, $otherCoach, $ada);
        GoogleDriveConnection::create(['user_id' => $ada->id, 'google_account_id' => 'account', 'google_email' => '', 'access_token' => 'access', 'connected_at' => now()]);
        AttendanceSession::create(['user_id' => $ada->id, 'check_in_at' => now()->setTime(14, 0)]);

        $response = $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR · PARTICIPANTS',
                'aria-label="Filter participants"',
                'Participant training overview',
                'Employee ID', 'Training session', 'Mentor',
                '>Ada Assigned</strong>', 'EMP-0001', 'L4 November', '9 Nov – 6 Dec 2026', 'Coach Carter',
                'href="https://drive.google.com/drive/folders/ada" target="_blank" rel="noopener noreferrer"', 'Open Drive ↗',
                '>Connected<', 'Account connected',
                'CHECKED IN', 'Nov 10 · 2:00 PM', 'elapsed',
                'href="'.route('coach.participants.show', [$session, $ada]).'"', 'Inspect',
                'Showing 1–1 of 1 participants',
            ], false)
            ->assertDontSee('L4 October')
            ->assertDontSee('Una Unassigned')
            ->assertDontSee('Nev Never')
            ->assertDontSee('Assigned to me')
            ->assertDontSee('Admin access');

        $this->assertSame(1, substr_count($response->getContent(), 'data-participant-row'));
    }

    public function test_after_reassignment_the_participant_leaves_the_previous_mentors_list(): void
    {
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pia Participant']);
        $session = TrainingSession::factory()->withMentors($ben)->create();
        $this->mentor($session, $ana, $participant);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('admin.sessions.assignments.update', [$session, $participant]), ['mentor_id' => $ben->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($ana)->get(route('coach.participants'))
            ->assertOk()
            ->assertSee('No participants match these filters.')
            ->assertDontSee('>Pia Participant</strong>', false);
        $this->actingAs($ben)->get(route('coach.participants'))
            ->assertOk()
            ->assertSee('>Pia Participant</strong>', false);
    }

    public function test_an_admin_sees_every_session_membership_and_can_filter_by_session(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $ada = User::factory()->create(['name' => 'Ada Alpha', 'employee_id' => null]);
        $bo = User::factory()->create(['name' => 'Bo Beta']);
        User::factory()->create(['name' => 'Cy Without Session']);
        $november = TrainingSession::factory()->create(['title' => 'L4 November', 'starts_on' => '2026-11-09', 'ends_on' => '2026-12-06']);
        $october = TrainingSession::factory()->create(['title' => 'L4 October', 'starts_on' => '2026-10-05', 'ends_on' => '2026-11-01']);
        $this->mentor($november, $coach, $ada);
        $october->participants()->attach([$ada->id, $bo->id], ['joined_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('coach.participants'))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="">All training sessions</option>',
                '<option value="'.$november->id.'" >L4 November (9 Nov – 6 Dec 2026)</option>',
                '<option value="'.$october->id.'" >L4 October (5 Oct – 1 Nov 2026)</option>',
                '>Ada Alpha</strong>', '—', 'L4 November', 'Coach Carter', 'href="'.route('coach.participants.show', [$november, $ada]).'"',
                '>Ada Alpha</strong>', 'L4 October', 'Not assigned', 'href="'.route('coach.participants.show', [$october, $ada]).'"',
                '>Bo Beta</strong>', 'L4 October', 'Not assigned',
                'Showing 1–3 of 3 participants',
            ], false)
            ->assertDontSee('Cy Without Session');
        $this->assertSame(3, substr_count($response->getContent(), 'data-participant-row'));

        $filtered = $this->actingAs($admin)->get(route('coach.participants', ['session' => $october->id]))
            ->assertOk()
            ->assertSee('<option value="'.$october->id.'" selected>L4 October', false)
            ->assertSeeInOrder(['>Ada Alpha</strong>', 'L4 October', '>Bo Beta</strong>'], false)
            ->assertDontSee('href="'.route('coach.participants.show', [$november, $ada]).'"', false);
        $this->assertSame(2, substr_count($filtered->getContent(), 'data-participant-row'));
    }

    public function test_a_mentor_is_offered_only_the_sessions_they_mentor(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        TrainingSession::factory()->withMentors($coach)->create(['title' => 'Mentored Session']);
        TrainingSession::factory()->create(['title' => 'Someone Elses Session']);

        $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertSee('Mentored Session')
            ->assertDontSee('Someone Elses Session');
    }

    public function test_an_unknown_session_filter_fails_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.participants', ['session' => 999999]))
            ->assertSessionHasErrors('session');
    }

    public function test_filters_keep_their_values_and_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $this->mentor(TrainingSession::factory()->create(), $coach, User::factory()->create(['name' => 'Some Person']));

        $this->actingAs($coach)
            ->get(route('coach.participants', ['search' => 'Nobody']))
            ->assertOk()
            ->assertSee('name="search" value="Nobody"', false)
            ->assertDontSee('name="path"', false)
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-participant-row', false)
            ->assertDontSee('>Some Person</strong>', false);
    }

    public function test_an_old_path_filter_is_ignored(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->create();
        $this->mentor($session, $coach, User::factory()->create(['name' => 'Mia Manual']));
        $this->mentor($session, $coach, User::factory()->create(['name' => 'Abe Automation']));

        $response = $this->actingAs($coach)
            ->get(route('coach.participants', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('>Mia Manual</strong>', false)
            ->assertSee('>Abe Automation</strong>', false);

        $this->assertSame(2, substr_count($response->getContent(), 'data-participant-row'));
    }

    public function test_rows_count_the_sessions_submissions_and_gate_evaluations_without_loading_them(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->create();
        $this->mentor($session, $coach, $participant);
        ActivitySubmission::factory()->count(2)->for($participant, 'participant')->for($session)->create();
        GateEvaluation::factory()->count(3)->for($participant, 'participant')->for($session)->create();
        ActivitySubmission::factory()->for($participant, 'participant')->create();
        GateEvaluation::factory()->for($participant, 'participant')->create();

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertViewHas('participants', fn (LengthAwarePaginator $rows): bool => $rows->count() === 1 && $rows->first()['submission_count'] === 2 && $rows->first()['gate_count'] === 3));

        $this->assertArrayNotHasKey(ActivitySubmission::class, $retrievedModels);
        $this->assertArrayNotHasKey(GateEvaluation::class, $retrievedModels);
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->participantListQueryCount(1), $this->participantListQueryCount(3));
    }

    /**
     * Add the participant to the session and make the coach a mentor of the session and the participant's current
     * mentor there.
     */
    private function mentor(TrainingSession $session, User $coach, User $participant): void
    {
        $session->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
        $session->mentors()->syncWithoutDetaching([$coach->id]);
        MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
    }

    /**
     * Create the given number of participants in a new session, each mentored there by a new coach and with a Drive
     * connection, a submission, a Gate evaluation and an open attendance session, and count the queries of that coach's
     * page request after a warm-up request.
     */
    private function participantListQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->create();
        $participants = User::factory()->count($participantCount)->create();

        foreach ($participants as $participant) {
            $this->mentor($session, $coach, $participant);
            GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => "account-{$participant->id}", 'google_email' => "drive-{$participant->id}@gmail.com", 'access_token' => 'access', 'connected_at' => now()]);
            ActivitySubmission::factory()->for($participant, 'participant')->for($session)->create();
            GateEvaluation::factory()->for($participant, 'participant')->for($session)->create();
            AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHour()]);
        }

        $this->actingAs($coach)->get(route('coach.participants'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.participants'))->assertOk()->assertSee('CHECKED IN');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Count the models of each class retrieved from the database while the callback runs.
     *
     * @return array<class-string, int>
     */
    private function retrievedModelCounts(Closure $callback): array
    {
        $counts = [];
        Event::listen('eloquent.retrieved: *', function (string $event) use (&$counts): void {
            $model = Str::after($event, 'eloquent.retrieved: ');
            $counts[$model] = ($counts[$model] ?? 0) + 1;
        });

        $callback();
        Event::forget('eloquent.retrieved: *');

        return $counts;
    }
}
