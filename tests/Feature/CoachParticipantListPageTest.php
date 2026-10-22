<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
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

    public function test_rows_show_the_coach_access_drive_and_attendance_of_each_participant(): void
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
        AttendanceSession::create(['user_id' => $assigned->id, 'check_in_at' => now()->setTime(14, 0)]);
        AttendanceSession::create(['user_id' => $unassigned->id, 'check_in_at' => now()->subDay()->setTime(9, 0), 'check_out_at' => now()->subDay()->setTime(10, 30), 'total_seconds' => 5400]);

        $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH · PARTICIPANTS',
                'aria-label="Filter participants"',
                'Participant training overview',
                '>Ada Assigned</strong>', 'Manual QA', 'Assigned to me', 'Coach Carter',
                'href="https://drive.google.com/drive/folders/ada" target="_blank" rel="noopener noreferrer"', 'Open Drive ↗',
                '>Connected<', 'Account connected',
                'CHECKED IN', 'Mar 10 · 2:00 PM', 'elapsed',
                'href="'.route('coach.participants.show', $assigned).'"', 'Inspect',
                '>Una Unassigned</strong>', 'Not assigned', 'Other Mentor', 'Not provided', 'Not Connected',
                'CHECKED OUT', 'Mar 9 · 10:30 AM', '1h 30m',
                '>Nev Never</strong>', 'Not assigned', 'Not checked in',
                'Showing 1–3 of 3 participants',
            ], false)
            ->assertDontSee('Admin access')
            ->assertDontSee('Daily Logs')
            ->assertDontSee('Assign reciprocal partners');
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

    public function test_filters_keep_their_values_and_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        User::factory()->create(['name' => 'Manual Person', 'training_path' => 'manual']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.participants', ['search' => 'Nobody', 'path' => 'automation']))
            ->assertOk()
            ->assertSee('name="search" value="Nobody"', false)
            ->assertSee('<option value="automation" selected>Automation</option>', false)
            ->assertDontSee('<option value="manual" selected>', false)
            ->assertSee('No participants match these filters.')
            ->assertDontSee('data-participant-row', false)
            ->assertDontSee('>Manual Person</strong>', false);
    }

    public function test_rows_count_submissions_and_gate_evaluations_without_loading_them(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        ActivitySubmission::factory()->count(2)->for($participant, 'participant')->create();
        GateEvaluation::factory()->count(3)->for($participant, 'participant')->create();

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($coach)->get(route('coach.participants'))
            ->assertOk()
            ->assertViewHas('participants', fn (LengthAwarePaginator $rows): bool => $rows->first()['submission_count'] === 2 && $rows->first()['gate_count'] === 3));

        $this->assertArrayNotHasKey(ActivitySubmission::class, $retrievedModels);
        $this->assertArrayNotHasKey(GateEvaluation::class, $retrievedModels);
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->participantListQueryCount(1), $this->participantListQueryCount(3));
    }

    /**
     * Create the given number of participants, each assigned to a new coach with a Drive connection, a submission, a
     * Gate evaluation and an open attendance session, and count the queries of that coach's page request after a
     * warm-up request.
     */
    private function participantListQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participants = User::factory()->count($participantCount)->create();
        $coach->assignedParticipants()->attach($participants->modelKeys());

        foreach ($participants as $participant) {
            GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => "account-{$participant->id}", 'google_email' => "drive-{$participant->id}@gmail.com", 'access_token' => 'access', 'connected_at' => now()]);
            ActivitySubmission::factory()->for($participant, 'participant')->create();
            GateEvaluation::factory()->for($participant, 'participant')->create();
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
