<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachParticipantListPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rows_show_the_coach_access_drive_daily_logs_and_attendance_of_each_participant(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 10)->setTime(15, 0));
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $otherCoach = User::factory()->create(['role' => 'coach', 'name' => 'Other Mentor']);
        $assigned = User::factory()->create(['name' => 'Ada Assigned', 'drive_link' => 'https://drive.google.com/drive/folders/ada']);
        $unassigned = User::factory()->create(['name' => 'Una Unassigned']);
        User::factory()->create(['name' => 'Nev Never']);
        $coach->assignedParticipants()->attach($assigned->id);
        $otherCoach->assignedParticipants()->attach($unassigned->id);
        GoogleDriveConnection::create(['user_id' => $assigned->id, 'google_account_id' => 'account', 'google_email' => '', 'access_token' => 'access', 'connected_at' => now()]);
        DailyLog::factory()->count(2)->for($assigned, 'participant')->create();
        AttendanceSession::create(['user_id' => $assigned->id, 'check_in_at' => now()->setTime(14, 0)]);
        AttendanceSession::create(['user_id' => $unassigned->id, 'check_in_at' => now()->subDay()->setTime(9, 0), 'check_out_at' => now()->subDay()->setTime(10, 30), 'total_seconds' => 5400]);

        $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH · PARTICIPANTS',
                'Assign reciprocal partners',
                'aria-label="Filter participants"',
                'Participant training overview',
                '>Ada Assigned</strong>', 'Manual QA', 'Assigned to me', 'Coach Carter',
                'href="https://drive.google.com/drive/folders/ada" target="_blank" rel="noopener noreferrer"', 'Open Drive ↗',
                '>Connected<', 'Account connected',
                'href="'.route('coach.participants.daily-logs', $assigned).'"', '>2</a>',
                'CHECKED IN', 'Mar 10 · 2:00 PM', 'elapsed',
                'href="'.route('coach.participants.show', $assigned).'"', 'Inspect',
                '>Una Unassigned</strong>', 'Not assigned', 'Other Mentor', 'Not provided', 'Not Connected',
                'CHECKED OUT', 'Mar 9 · 10:30 AM', '1h 30m',
                '>Nev Never</strong>', 'Not assigned', 'Not checked in',
                'Showing 1–3 of 3 participants',
            ], false)
            ->assertDontSee('Admin access')
            ->assertDontSee(route('coach.participants.daily-logs', $unassigned), false);
    }

    public function test_admin_has_admin_access_to_every_participant(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.participants'))
            ->assertOk()
            ->assertSeeInOrder(['>'.$participant->name.'</strong>', 'Admin access', $coach->name], false)
            ->assertDontSee('Assigned to me')
            ->assertDontSee('Not assigned');
    }

    public function test_pairing_form_lists_active_candidates_with_their_mentor_and_the_active_pairs_once(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Carter']);
        $ann = User::factory()->create(['name' => 'Ann Alpha', 'email' => 'ann@example.test']);
        $ben = User::factory()->create(['name' => 'Ben Beta', 'email' => 'ben@example.test']);
        $cal = User::factory()->create(['name' => 'Cal Gamma', 'email' => 'cal@example.test']);
        User::factory()->create(['name' => 'Ina Inactive', 'is_active' => false]);
        $coach->assignedParticipants()->attach([$ann->id, $ben->id, $cal->id]);
        $activePair = WeekFourInterviewPair::create(['participant_one_id' => $ann->id, 'participant_two_id' => $ben->id, 'assigned_by_id' => $coach->id, 'status' => 'active']);
        $inactivePair = WeekFourInterviewPair::create(['participant_one_id' => $ann->id, 'participant_two_id' => $cal->id, 'assigned_by_id' => $coach->id, 'status' => 'inactive']);

        $response = $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.route('coach.week-four.pairs.assign').'"',
                'name="participant_one_id"',
                '<option value="'.$ann->id.'">Ann Alpha — Mentor: Coach Carter (ann@example.test)</option>',
                'name="participant_two_id"',
                '<option value="'.$cal->id.'">Cal Gamma — Mentor: Coach Carter (cal@example.test)</option>',
                'Assign pair',
                'Active peer interview pairs',
                'Ann Alpha (ann@example.test) ↔ Ben Beta (ben@example.test)',
                'action="'.route('coach.week-four.pairs.unpair', $activePair).'"',
                'Unpair',
            ], false)
            ->assertDontSee('Ina Inactive — Mentor')
            ->assertDontSee(route('coach.week-four.pairs.unpair', $inactivePair), false);

        $this->assertSame(1, substr_count($response->getContent(), '↔'));
    }

    public function test_a_coach_is_offered_only_the_participants_and_pairs_they_manage(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $otherCoach = User::factory()->create(['role' => 'coach']);
        $ann = User::factory()->create(['name' => 'Ann Alpha']);
        $ben = User::factory()->create(['name' => 'Ben Beta']);
        $oli = User::factory()->create(['name' => 'Oli Other']);
        $una = User::factory()->create(['name' => 'Una Unassigned']);
        $coach->assignedParticipants()->attach([$ann->id, $ben->id]);
        $otherCoach->assignedParticipants()->attach($oli->id);
        $managedPair = WeekFourInterviewPair::create(['participant_one_id' => $ann->id, 'participant_two_id' => $ben->id, 'assigned_by_id' => $coach->id, 'status' => 'active']);
        $mixedPair = WeekFourInterviewPair::create(['participant_one_id' => $ann->id, 'participant_two_id' => $oli->id, 'assigned_by_id' => $otherCoach->id, 'status' => 'active']);
        $otherPair = WeekFourInterviewPair::create(['participant_one_id' => $oli->id, 'participant_two_id' => $una->id, 'assigned_by_id' => $otherCoach->id, 'status' => 'active']);

        $response = $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertSee('<option value="'.$ann->id.'">Ann Alpha — Mentor:', false)
            ->assertSee('<option value="'.$ben->id.'">Ben Beta — Mentor:', false)
            ->assertDontSee('<option value="'.$oli->id.'">', false)
            ->assertDontSee('<option value="'.$una->id.'">', false)
            ->assertSee(route('coach.week-four.pairs.unpair', $managedPair), false)
            ->assertDontSee(route('coach.week-four.pairs.unpair', $mixedPair), false)
            ->assertDontSee(route('coach.week-four.pairs.unpair', $otherPair), false);

        $this->assertSame(1, substr_count($response->getContent(), '↔'));

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.participants'))
            ->assertOk()
            ->assertSee('<option value="'.$oli->id.'">', false)
            ->assertSee('<option value="'.$una->id.'">', false)
            ->assertSee(route('coach.week-four.pairs.unpair', $mixedPair), false)
            ->assertSee(route('coach.week-four.pairs.unpair', $otherPair), false);
    }

    public function test_filters_keep_their_values_and_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        User::factory()->create(['name' => 'Manual Person', 'training_path' => 'manual']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.participants', ['search' => 'Nobody', 'path' => 'automation']))
            ->assertOk()
            ->assertSee('name="search" value="Nobody"', false)
            ->assertSee('<option value="automation" selected>Automation QA</option>', false)
            ->assertDontSee('<option value="manual" selected>', false)
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-participant-row', false)
            ->assertDontSee('>Manual Person</strong>', false);
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->participantListQueryCount(1), $this->participantListQueryCount(3));
    }

    /**
     * Create the given number of participants, each assigned to a new coach with a Drive connection, a submission, a
     * Gate evaluation, a daily log, an open attendance session and an active pair with the next participant, and count
     * the queries of that coach's page request after a warm-up request.
     */
    private function participantListQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participants = User::factory()->count($participantCount + 1)->create();
        $coach->assignedParticipants()->attach($participants->modelKeys());

        foreach ($participants->take($participantCount) as $index => $participant) {
            GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => "account-{$participant->id}", 'google_email' => "drive-{$participant->id}@gmail.com", 'access_token' => 'access', 'connected_at' => now()]);
            ActivitySubmission::factory()->for($participant, 'participant')->create();
            GateEvaluation::factory()->for($participant, 'participant')->create();
            DailyLog::factory()->for($participant, 'participant')->create();
            AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()->subHour()]);
            WeekFourInterviewPair::create(['participant_one_id' => $participant->id, 'participant_two_id' => $participants[$index + 1]->id, 'assigned_by_id' => $coach->id, 'status' => 'active']);
        }

        $this->actingAs($coach)->get(route('coach.participants'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.participants'))->assertOk()->assertSee('CHECKED IN');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
