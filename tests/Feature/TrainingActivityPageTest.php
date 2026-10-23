<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveSyncStatus;
use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveSync;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrainingActivityPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_activity_page_shows_the_instructions_requirements_and_submission_tabs(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee('role="tablist" aria-label="Activity sections"', false)
            ->assertSeeInOrder([
                'id="activity-tab-instructions"',
                'id="activity-tab-requirements"',
                'id="activity-tab-submission"',
            ], false)
            ->assertSee('>Requirements for this activity</h2>', false)
            ->assertDontSee('QA</h2>', false)
            ->assertDontSee('activity-tab-validation-lab', false);
    }

    #[DataProvider('submissionStates')]
    public function test_single_main_action_matches_the_submission_state(?string $status, string $actionLabel, bool $isRevisionForm, string $selectedTab): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        if ($status !== null) {
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status === 'draft' ? 'draft' : 'submitted']);
        }

        $html = $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div id="activity-main-action"[^>]*>(.*?)<\/div>/s', $html, $mainAction));
        $this->assertStringContainsString($actionLabel, $mainAction[1]);
        $this->assertStringContainsString($isRevisionForm
            ? 'method="POST" action="'.route('training.submission.revise', [$week, $activity]).'"'
            : 'href="'.route('training.submission.edit', [$week, $activity]).'"', $mainAction[1]);
        $this->assertSame(1, substr_count($html, $actionLabel));
        $this->assertStringContainsString('id="activity-tab-'.$selectedTab.'" aria-controls="activity-panel-'.$selectedTab.'" aria-selected="true"', $html);
    }

    /**
     * @return array<string, array{?string, string, bool, string}>
     */
    public static function submissionStates(): array
    {
        return [
            'not started' => [null, 'Start submission →', false, 'instructions'],
            'draft' => ['draft', 'Continue submission →', false, 'submission'],
            'needs revision' => ['needs_revision', 'Revise submission →', true, 'submission'],
        ];
    }

    #[DataProvider('viewSubmissionLinks')]
    public function test_submission_tab_links_to_the_latest_version_only_once_it_is_submitted(string $versionStatus, int $linkCount): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $versionStatus]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $versionStatus]);

        $html = $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))->assertOk()->getContent();

        $this->assertSame($linkCount, substr_count($html, 'href="'.route('training.submission.show', [$week, $activity, $version]).'"'));
    }

    public function test_the_week_two_validation_checklist_opens_without_a_lab_record(): void
    {
        [$participant, $week, , $session] = $this->activityFixture();
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1, 'title' => 'Validation Checklist']);

        $html = $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div id="activity-main-action"[^>]*>(.*?)<\/div>/s', $html, $mainAction));
        $this->assertStringContainsString('href="'.route('training.submission.edit', [$week, $activity]).'"', $mainAction[1]);
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function viewSubmissionLinks(): array
    {
        return [
            'draft' => ['draft', 0],
            'submitted' => ['submitted', 1],
        ];
    }

    #[DataProvider('driveSyncStatuses')]
    public function test_google_drive_card_shows_the_latest_workbook_sync_status_and_only_a_failed_sync_error(?string $status, string $statusLabel, bool $showsError): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'connected_at' => now()]);
        if ($status !== null) {
            GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => GoogleDriveSyncStatus::Failed, 'error' => 'An earlier sync failed.']);
            GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => $status, 'error' => 'Week 2.xlsx: Google Drive sync failed.']);
        }

        $response = $this->actingAs($participant->fresh())->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee('Sync status: '.$statusLabel)
            ->assertDontSee('An earlier sync failed.');

        $showsError
            ? $response->assertSee('Week 2.xlsx: Google Drive sync failed.')
            : $response->assertDontSee('Week 2.xlsx: Google Drive sync failed.');
    }

    /**
     * @return array<string, array{?string, string, bool}>
     */
    public static function driveSyncStatuses(): array
    {
        return [
            'never synced' => [null, 'Not synced yet', false],
            'enqueued' => ['enqueued', 'Enqueued', false],
            'in progress' => ['in_progress', 'In progress', false],
            'retrying' => ['retrying', 'Retrying', false],
            'completed' => ['completed', 'Completed', false],
            'completed with errors' => ['completed_with_errors', 'Completed with errors', true],
            'failed' => ['failed', 'Failed', true],
        ];
    }

    public function test_google_drive_card_shows_the_last_successful_workbook_sync(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'connected_at' => now()]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => GoogleDriveSyncStatus::Completed, 'last_successful_sync_at' => '2026-10-20 09:30:00']);

        $this->actingAs($participant->fresh())->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee('Last successful sync: Oct 20, 2026 9:30 AM');
    }

    public function test_google_drive_card_counts_the_completed_and_failed_evidence_exports_of_the_latest_version(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'connected_at' => now()]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        foreach (['completed', 'synced', 'failed', 'in_progress'] as $status) {
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->create()->googleDriveExport()->create(['status' => $status]);
        }

        $this->actingAs($participant->fresh())->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee('Evidence: 2/4 completed · 1 failed');
    }

    #[DataProvider('attemptStatuses')]
    public function test_attempt_history_shows_the_label_tone_and_next_step_of_each_attempt_status(string $status, string $label, string $badgeClasses, string $nextStep): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status]);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $status === 'draft' ? 'draft' : 'submitted']);

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee('<span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-bold '.$badgeClasses.'" role="status">'.$label.'</span>', false)
            ->assertSee($nextStep);
    }

    public function test_activity_page_and_submission_form_show_the_submission_status_label(): void
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertOk()
            ->assertSee('Status: <strong class="text-slate-100">Needs revision</strong>', false);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('Current status: <strong class="text-slate-100">Needs revision</strong>', false)
            ->assertSee('· Version 1 (Submitted)')
            ->assertDontSee('needs_revision');
    }

    /**
     * Each attempt status, its attempt history label, the badge tone classes and the next step shown with it.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function attemptStatuses(): array
    {
        $awaitingReview = 'Your submission is in the review process.';
        $accepted = 'This activity has been accepted by your mentor.';

        return [
            'draft' => ['draft', 'DRAFT', 'border-brand text-violet-200', 'Your work is saved as a draft.'],
            'submitted' => ['submitted', 'SUBMITTED', 'border-accent text-cyan-200', $awaitingReview],
            'resubmitted' => ['resubmitted', 'RESUBMITTED', 'border-accent text-cyan-200', $awaitingReview],
            'under review' => ['under_review', 'UNDER REVIEW', 'border-accent text-cyan-200', $awaitingReview],
            'needs revision' => ['needs_revision', 'NEEDS REVISION', 'border-amber-500 text-amber-200', 'Your mentor requested changes to this submission.'],
            'approved' => ['approved', 'APPROVED', 'border-emerald-400 text-emerald-200', $accepted],
            'completed' => ['completed', 'COMPLETED', 'border-emerald-400 text-emerald-200', $accepted],
            'failed' => ['failed', 'FAILED', 'border-danger text-rose-200', 'This attempt was not accepted by your mentor and is now closed.'],
        ];
    }

    public function test_activity_page_query_count_does_not_grow_with_requirements_and_attempts(): void
    {
        $this->assertSame($this->activityPageQueryCount(1), $this->activityPageQueryCount(3));
    }

    /**
     * Build an activity with the given number of requirements and failed attempts (each with evidence, a coach
     * review and a review request), and count the queries of an activity page request after a warm-up request.
     */
    private function activityPageQueryCount(int $rows): int
    {
        [$participant, $week, $activity, $session] = $this->activityFixture();

        foreach (range(1, $rows) as $position) {
            $requirement = ActivityRequirement::factory()->for($activity)->create(['position' => $position]);
            $evidenceRequirement = EvidenceRequirement::factory()->for($requirement)->create();
            $attempt = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['attempt_number' => $position, 'status' => 'failed', 'submitted_at' => now()]);
            $version = SubmissionVersion::factory()->for($attempt, 'submission')->create(['status' => 'failed', 'submitted_at' => now()]);
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
            SubmissionReview::factory()->create(['submission_version_id' => $version->id, 'decision' => 'rejected']);
            ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $attempt->id, 'status' => 'declined', 'requested_at' => now()]);
        }

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))->assertOk()->assertSee('Attempt '.$rows);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * A checked-in manual participant, a Week 2 activity in position 2 and a session of the week's program that the
     * participant is in.
     *
     * @return array{User, TrainingWeek, Activity, TrainingSession}
     */
    private function activityFixture(): array
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 2]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $week, $activity, $session];
    }
}
