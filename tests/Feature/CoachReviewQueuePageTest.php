<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachReviewQueuePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_queue_lists_reviewable_submissions_newest_first_with_a_link_to_their_latest_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create(['title' => 'Exploratory charter']);
        $revised = $this->submission($activity, 'Rita Revised', 'needs_revision', now()->subDay());
        $olderVersion = SubmissionVersion::factory()->for($revised, 'submission')->create(['version_number' => 1]);
        $latestVersion = SubmissionVersion::factory()->for($revised, 'submission')->create(['version_number' => 2]);
        $this->submission($activity, 'Nora Noversion', 'submitted', now());
        $this->submission($activity, 'Dora Draft', 'draft', now());
        $this->submission($activity, 'Abe Approved', 'approved', now());

        $sessionLabel = e($revised->trainingSession->titleWithDates());

        $this->actingAs($admin)->get(route('coach.reviews'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR · REVIEW QUEUE',
                'Submission reviews',
                '>Nora Noversion</h2>', 'Training session:', $sessionLabel, 'Week 3 · Exploratory charter', 'Status:', 'border-accent text-cyan-200">Submitted</span>',
                '>Rita Revised</h2>', 'Training session:', $sessionLabel, 'Week 3 · Exploratory charter', 'Status:', 'border-amber-500 text-amber-200">Needs revision</span>',
                'href="'.route('coach.reviews.show', $latestVersion).'"', '>Review latest version</a>',
            ], false)
            ->assertDontSee('href="'.route('coach.reviews.show', $olderVersion).'"', false)
            ->assertDontSee('>Dora Draft</h2>', false)
            ->assertDontSee('>Abe Approved</h2>', false)
            ->assertDontSee('No submissions to review.');

        $this->assertSame(1, substr_count($this->actingAs($admin)->get(route('coach.reviews'))->getContent(), 'Review latest version'));
    }

    public function test_a_mentor_sees_only_their_current_participants_submissions_in_the_sessions_they_mentor(): void
    {
        ['ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'sessionX' => $sessionX, 'sessionY' => $sessionY, 'versions' => $versions] = $this->mentorScopeFixture();

        $response = $this->actingAs($ben)->get(route('coach.reviews'))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="'.$sessionX->id.'" >'.e($sessionX->titleWithDates()).'</option>',
                '>Pat Participant</option>',
                '>Pat Participant</h2>', 'Training session:', e($sessionX->titleWithDates()),
                'href="'.route('coach.reviews.show', $versions['patInX']).'"',
            ], false)
            ->assertDontSee(route('coach.reviews.show', $versions['patInY']), false)
            ->assertDontSee(route('coach.reviews.show', $versions['quinnInX']), false)
            ->assertDontSee('Quinn Other')
            ->assertDontSee(e($sessionY->titleWithDates()), false);
        $this->assertSame(1, substr_count($response->getContent(), 'Review latest version'));

        $this->actingAs($cy)->get(route('coach.reviews'))
            ->assertOk()
            ->assertSee('href="'.route('coach.reviews.show', $versions['patInY']).'"', false)
            ->assertDontSee(route('coach.reviews.show', $versions['patInX']), false)
            ->assertDontSee(route('coach.reviews.show', $versions['quinnInX']), false);

        $this->actingAs($ana)->get(route('coach.reviews'))
            ->assertOk()
            ->assertSee('No submissions to review.')
            ->assertDontSee('Pat Participant')
            ->assertDontSee('Review latest version');
    }

    public function test_an_admin_sees_every_submission_and_the_session_filter_narrows_the_queue(): void
    {
        ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'versions' => $versions] = $this->mentorScopeFixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.reviews'))
            ->assertOk()
            ->assertSee(route('coach.reviews.show', $versions['patInX']), false)
            ->assertSee(route('coach.reviews.show', $versions['patInY']), false)
            ->assertSee(route('coach.reviews.show', $versions['quinnInX']), false);

        $this->actingAs($admin)->get(route('coach.reviews', ['session' => $sessionY->id]))
            ->assertOk()
            ->assertSee('<option value="'.$sessionY->id.'" selected>'.e($sessionY->titleWithDates()).'</option>', false)
            ->assertSee('<option value="'.$sessionX->id.'" >'.e($sessionX->titleWithDates()).'</option>', false)
            ->assertSee('href="'.route('coach.reviews.show', $versions['patInY']).'"', false)
            ->assertDontSee(route('coach.reviews.show', $versions['patInX']), false)
            ->assertDontSee(route('coach.reviews.show', $versions['quinnInX']), false)
            ->assertSee('>Clear</a>', false);
    }

    public function test_an_unknown_session_filter_fails_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.reviews', ['session' => 999]))
            ->assertSessionHasErrors(['session']);
    }

    public function test_filters_apply_and_the_pagination_links_keep_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activity = Activity::factory()->create();

        foreach (range(1, 26) as $index) {
            $this->submission($activity, "Revision {$index}", 'needs_revision', now()->subMinutes($index));
        }

        $this->submission($activity, 'Sam Submitted', 'submitted', now());

        $this->actingAs($admin)->get(route('coach.reviews', ['status' => 'needs_revision']))
            ->assertOk()
            ->assertSee('>Revision 1</h2>', false)
            ->assertDontSee('>Sam Submitted</h2>', false)
            ->assertDontSee('>Revision 26</h2>', false)
            ->assertSee(e(route('coach.reviews', ['status' => 'needs_revision', 'page' => 2])), false);

        $this->actingAs($admin)->get(route('coach.reviews', ['status' => 'needs_revision', 'page' => 2]))
            ->assertOk()
            ->assertSee('>Revision 26</h2>', false)
            ->assertDontSee('>Revision 1</h2>', false);
    }

    public function test_filter_form_offers_the_active_programs_weeks_activities_statuses_and_participants(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $weekTwo = TrainingWeek::factory()->for($program)->create(['week_number' => 2]);
        $weekThree = TrainingWeek::factory()->for($program)->create(['week_number' => 3]);
        TrainingWeek::factory()->for($program)->create(['week_number' => 1]);
        $charter = Activity::factory()->for($weekThree, 'week')->create(['title' => 'Exploratory charter', 'position' => 1]);
        $checklist = Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Validation checklist', 'position' => 2]);
        Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Retired activity', 'is_active' => false]);
        Activity::factory()->for(TrainingWeek::factory()->for(Program::factory())->state(['week_number' => 4]), 'week')->create(['title' => 'Later program activity']);
        $participant = User::factory()->create(['name' => 'Zed Participant']);
        User::factory()->create(['name' => 'Amy Participant']);

        $response = $this->actingAs($admin)->get(route('coach.reviews', ['week' => 3, 'activity' => $charter->id, 'status' => 'under_review', 'participant' => $participant->id]))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.route('coach.reviews').'"', 'aria-label="Filter submissions"',
                'for="review-session"', '<option value="">All training sessions</option>',
                'for="review-participant"', '<option value="">All participants</option>', '>Amy Participant</option>', '<option value="'.$participant->id.'" selected>Zed Participant</option>',
                'for="review-week"', '<option value="">All weeks</option>', '<option value="1" >Week 1</option>', '<option value="2" >Week 2</option>', '<option value="3" selected>Week 3</option>',
                'for="review-activity"', '<option value="'.$checklist->id.'" >Week 2 · Validation checklist</option>', '<option value="'.$charter->id.'" selected>Week 3 · Exploratory charter</option>',
                'for="review-status"', '<option value="submitted" >Submitted</option>', '<option value="resubmitted" >Resubmitted</option>', '<option value="under_review" selected>Under review</option>', '<option value="needs_revision" >Needs revision</option>',
                '>Filter</button>', 'href="'.route('coach.reviews').'"', '>Clear</a>',
                'No submissions match the selected filters.',
            ], false)
            ->assertDontSee('Week 4</option>', false)
            ->assertDontSee('for="review-path"', false)
            ->assertDontSee('Retired activity')
            ->assertDontSee('Later program activity')
            ->assertDontSee('No submissions to review.');

        $this->assertSame(1, substr_count($response->getContent(), '>Amy Participant</option>'));
    }

    public function test_week_one_submissions_are_listed_and_the_week_filter_offers_week_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $weeks = collect(range(1, 4))->mapWithKeys(fn (int $weekNumber): array => [
            $weekNumber => TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]),
        ]);
        $weekOneActivity = Activity::factory()->for($weeks[1], 'week')->create(['title' => 'Kickoff deliverable']);
        $this->submission($weekOneActivity, 'Wendy WeekOne', 'submitted', now());
        $this->submission(Activity::factory()->for($weeks[2], 'week')->create(), 'Tom WeekTwo', 'submitted', now());

        $this->actingAs($admin)->get(route('coach.reviews', ['week' => 1]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSeeInOrder([
                '<option value="1" selected>Week 1</option>', '<option value="2" >Week 2</option>', '<option value="3" >Week 3</option>', '<option value="4" >Week 4</option>',
                '<option value="'.$weekOneActivity->id.'" >Week 1 · Kickoff deliverable</option>',
                '>Wendy WeekOne</h2>', 'Week 1 · Kickoff deliverable',
            ], false)
            ->assertDontSee('>Tom WeekTwo</h2>', false);
    }

    public function test_week_activity_and_participant_filters_narrow_the_queue_and_an_old_path_filter_is_ignored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $weekTwoActivity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create();
        $weekThreeActivity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create();
        $manualTwo = $this->submission($weekTwoActivity, 'Manual Two', 'submitted', now());
        $this->submission($weekThreeActivity, 'Manual Three', 'submitted', now());
        $automation = ActivitySubmission::factory()
            ->for($weekTwoActivity)
            ->for(User::factory()->state(['name' => 'Automation Two']), 'participant')
            ->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($admin)->get(route('coach.reviews', ['week' => 3]))
            ->assertOk()->assertSee('>Manual Three</h2>', false)->assertDontSee('>Manual Two</h2>', false)->assertDontSee('>Automation Two</h2>', false);
        $this->actingAs($admin)->get(route('coach.reviews', ['activity' => $weekTwoActivity->id]))
            ->assertOk()->assertSee('>Manual Two</h2>', false)->assertSee('>Automation Two</h2>', false)->assertDontSee('>Manual Three</h2>', false);
        $this->actingAs($admin)->get(route('coach.reviews', ['path' => 'automation']))
            ->assertOk()->assertSessionHasNoErrors()->assertSee('>Automation Two</h2>', false)->assertSee('>Manual Two</h2>', false)->assertSee('>Manual Three</h2>', false)->assertDontSee('>Clear</a>', false);
        $this->actingAs($admin)->get(route('coach.reviews', ['participant' => $manualTwo->user_id]))
            ->assertOk()->assertSee('>Manual Two</h2>', false)->assertDontSee('>Automation Two</h2>', false)->assertDontSee('>Manual Three</h2>', false);
        $this->actingAs($admin)->get(route('coach.reviews', ['participant' => $automation->user_id, 'week' => 3]))
            ->assertOk()->assertSee('No submissions match the selected filters.');
    }

    public function test_unfiltered_queue_has_no_clear_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reviews'))
            ->assertOk()
            ->assertSee('aria-label="Filter submissions"', false)
            ->assertDontSee('>Clear</a>', false);
    }

    #[DataProvider('statusFilterValues')]
    public function test_status_filter_accepts_the_review_statuses_and_the_gate_statuses_it_lists(string $status, bool $isAccepted): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reviews', ['status' => $status]));

        if ($isAccepted) {
            $response->assertOk()->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
        }
    }

    #[DataProvider('statusFilterValues')]
    public function test_report_export_status_filter_accepts_the_same_statuses_as_the_queue(string $status, bool $isAccepted): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.export', ['type' => 'reviews', 'status' => $status]));

        if ($isAccepted) {
            $response->assertOk()->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
        }
    }

    /**
     * Every submission and Gate status value and whether the mixed `status` filter accepts it: the four submission
     * statuses of the queue and the three Gate statuses of the Gate export.
     *
     * @return array<string, array{string, bool}>
     */
    public static function statusFilterValues(): array
    {
        return [
            'submitted' => ['submitted', true],
            'resubmitted' => ['resubmitted', true],
            'under review' => ['under_review', true],
            'needs revision' => ['needs_revision', true],
            'gate not ready' => ['not_ready', true],
            'failed' => ['failed', true],
            'gate passed' => ['passed', true],
            'draft' => ['draft', false],
            'approved' => ['approved', false],
            'completed' => ['completed', false],
            'gate ready for review' => ['ready_for_review', false],
            'gate awaiting review' => ['awaiting_review', false],
        ];
    }

    public function test_empty_queue_shows_a_notice_instead_of_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reviews'))
            ->assertOk()
            ->assertSee('No submissions to review.')
            ->assertDontSee('aria-label="Submissions to review"', false);
    }

    public function test_queue_loads_only_the_latest_version_of_each_submission(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();

        foreach (['Ann Attempt', 'Ben Attempt'] as $participantName) {
            $submission = $this->submission($activity, $participantName, 'submitted', now(), mentor: $coach);

            foreach ([1, 2, 3] as $versionNumber) {
                $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $versionNumber]);
                SubmissionEvidence::factory()->for($version, 'submissionVersion')->create();
                SubmissionReview::factory()->for($version, 'submissionVersion')->create();
            }
        }

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($coach)
            ->get(route('coach.reviews'))
            ->assertOk());

        $this->assertSame(2, $retrievedModels[SubmissionVersion::class]);
        $this->assertArrayNotHasKey(SubmissionEvidence::class, $retrievedModels);
        $this->assertArrayNotHasKey(SubmissionReview::class, $retrievedModels);
    }

    public function test_queue_query_count_does_not_grow_with_the_number_of_submissions(): void
    {
        $this->assertSame($this->reviewQueueQueryCount(1), $this->reviewQueueQueryCount(3));
    }

    /**
     * Sessions X and Y of one program and a submitted Week 1 version each of Pat's work in X and in Y and of Quinn's
     * work in X. In X an admin assigned Ana and then Ben as Pat's mentor, and Dan mentors Quinn; in Y Cy mentors Pat.
     *
     * @return array{ana: User, ben: User, cy: User, sessionX: TrainingSession, sessionY: TrainingSession, versions: array{patInX: SubmissionVersion, patInY: SubmissionVersion, quinnInX: SubmissionVersion}}
     */
    private function mentorScopeFixture(): array
    {
        [$ana, $ben, $cy, $dan] = User::factory()->count(4)->create(['role' => 'coach'])->all();
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 1]), 'week')->create();
        $sessionX = TrainingSession::factory()->forWeek($activity->week)->withMentors($ana, $ben, $dan)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($activity->week)->withMentors($cy)->create(['title' => 'Session Y']);
        MentorAssignment::factory()->ended()->create(['training_session_id' => $sessionX->id, 'participant_id' => $pat->id, 'mentor_id' => $ana->id]);
        MentorAssignment::factory()->create(['training_session_id' => $sessionX->id, 'participant_id' => $pat->id, 'mentor_id' => $ben->id]);
        MentorAssignment::factory()->create(['training_session_id' => $sessionX->id, 'participant_id' => $quinn->id, 'mentor_id' => $dan->id]);
        MentorAssignment::factory()->create(['training_session_id' => $sessionY->id, 'participant_id' => $pat->id, 'mentor_id' => $cy->id]);
        $version = fn (User $participant, TrainingSession $trainingSession): SubmissionVersion => SubmissionVersion::factory()
            ->for(ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->state(['training_session_id' => $trainingSession->id, 'status' => 'submitted', 'submitted_at' => now()]), 'submission')
            ->create(['version_number' => 1]);

        return [
            'ana' => $ana,
            'ben' => $ben,
            'cy' => $cy,
            'sessionX' => $sessionX,
            'sessionY' => $sessionY,
            'versions' => [
                'patInX' => $version($pat, $sessionX),
                'patInY' => $version($pat, $sessionY),
                'quinnInX' => $version($quinn, $sessionX),
            ],
        ];
    }

    /**
     * Create a submission of the activity by a new participant with the given name, status and submission time, in the
     * given session (or the activity's default one), with the given coach as the participant's current mentor there.
     */
    private function submission(Activity $activity, string $participantName, string $status, CarbonInterface $submittedAt, ?User $mentor = null, ?TrainingSession $trainingSession = null): ActivitySubmission
    {
        $submission = ActivitySubmission::factory()
            ->for($activity)
            ->for(User::factory()->state(['name' => $participantName]), 'participant')
            ->create(['status' => $status, 'submitted_at' => $submittedAt, ...($trainingSession ? ['training_session_id' => $trainingSession->id] : [])]);

        if ($mentor instanceof User) {
            MentorAssignment::factory()->create(['training_session_id' => $submission->training_session_id, 'participant_id' => $submission->user_id, 'mentor_id' => $mentor->id]);
        }

        return $submission;
    }

    /**
     * Create the given number of submitted submissions of a new activity (each by a new participant whom the coach
     * mentors, with two versions) and count the queries of the coach's queue request filtered to that activity after a
     * warm-up request.
     */
    private function reviewQueueQueryCount(int $submissionCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();

        foreach (range(1, $submissionCount) as $index) {
            $submission = $this->submission($activity, "Participant {$index}", 'submitted', now(), mentor: $coach);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2]);
        }

        $url = route('coach.reviews', ['activity' => $activity->id]);
        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee('Review latest version');
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
