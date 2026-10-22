<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachSubmissionReviewPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigned_coach_sees_the_submission_and_a_single_main_action_for_the_review_form(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('submitted', [
            'feature_area' => 'Checkout',
            'exact_ai_output' => '**AI said this**',
        ]);
        $version->update(['participant_note' => 'Checked against staging.']);
        SubmissionInteraction::create(['submission_version_id' => $version->id, 'sequence' => 1, 'prompt' => 'First prompt', 'output' => 'First output']);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Bug link', 'evidence_type' => 'link', 'url' => 'https://example.com/bug', 'content' => null]);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Notes', 'evidence_type' => 'text', 'content' => 'Plain text evidence']);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Empty', 'evidence_type' => 'text', 'content' => null]);

        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $response->assertSeeInOrder([
            'SUBMISSION REVIEW',
            '>Exploratory charter</h1>',
            'Pat Participant · Version 1 · Submitted',
            'form="submission-review-form"', '>Save Review</button>',
            'action="'.route('coach.reviews.claim', $version).'"', '>Start Review</button>',
            'Submitted Activity Details',
            '>Feature / Area</strong>', 'Checkout',
            '>Exact AI Output</strong>', '<strong>AI said this</strong>',
            'Prompt / output history', 'Prompt 1', 'First prompt', 'AI Output 1', 'First output',
            'Evidence',
            '>Bug link</strong>', 'Type: Link', 'href="https://example.com/bug" target="_blank" rel="noopener noreferrer"', '>Open evidence</a>',
            '>Notes</strong>', 'Type: Text', 'Text evidence', 'Plain text evidence',
            '>Empty</strong>', 'No evidence content provided.',
            'Participant Note', 'Checked against staging.',
            'Coach Review',
            'id="submission-review-form"', 'data-markdown-flush-on-submit',
            'for="decision"', 'id="decision" name="decision" aria-describedby="decision-help" required',
            '<option value="">Select a decision...</option>',
            '<option value="approved" >Passed</option>', '<option value="needs_revision" >Revise</option>', '<option value="rejected" >Failed</option>',
            'for="comments"', 'id="comments" name="comments"', 'aria-describedby="comments-help"',
        ], false);

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, '>Save Review</button>'));
        $this->assertSame(1, substr_count($content, 'from-brand to-brand-deep'), 'Save Review is the only primary button.');
        $response->assertDontSee('Create Retry')->assertDontSee('Read only')->assertDontSee('Current Review');
    }

    public function test_text_evidence_and_the_participant_note_render_as_escaped_markdown(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('submitted');
        $version->update(['participant_note' => "Checked **staging** <script>alert('note')</script>"]);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Notes', 'evidence_type' => 'text', 'content' => "- First *step*\n- Second step"]);

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'Text evidence', 'class="formatted-text markdown"', '<li>First <em>step</em></li>', '<li>Second step</li>',
                'Participant Note', 'class="formatted-text markdown"', 'Checked <strong>staging</strong>', '&lt;script&gt;',
            ], false)
            ->assertDontSee("<script>alert('note')</script>", false)
            ->assertDontSee('whitespace-pre-wrap', false);
    }

    public function test_a_missing_participant_note_reads_not_provided(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('submitted');
        $version->update(['participant_note' => null]);

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder(['Participant Note', '<span class="text-muted">Not provided</span>'], false);
    }

    public function test_the_latest_review_is_shown_as_the_current_review(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('needs_revision');
        $reviewer = User::factory()->create(['role' => 'coach', 'name' => 'Rae Reviewer']);
        SubmissionReview::factory()->for($version, 'submissionVersion')->for($reviewer, 'reviewer')->create(['decision' => 'needs_revision', 'comments' => 'Add **steps**', 'reviewed_at' => '2026-10-20 14:30:00']);
        SubmissionReview::factory()->for($version, 'submissionVersion')->for($reviewer, 'reviewer')->create(['decision' => 'approved', 'comments' => 'Older feedback', 'reviewed_at' => '2026-10-19 09:00:00']);

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'Current Review',
                'Decision: Revise · Reviewed by Rae Reviewer · October 20, 2026 14:30',
                'Feedback', 'Add <strong>steps</strong>',
                'id="submission-review-form"',
            ], false)
            ->assertDontSee('Older feedback')
            ->assertDontSee('>Start Review</button>', false);
    }

    public function test_failed_submission_offers_a_retry_instead_of_a_claim(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('failed');

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.route('coach.reviews.retry', $version).'"',
                'This failed attempt is immutable. Create a separate retry attempt for the participant.',
                '>Create Retry</button>',
            ], false)
            ->assertDontSee('>Start Review</button>', false)
            ->assertSee('>Save Review</button>', false);
    }

    public function test_unassigned_coach_sees_a_read_only_notice_without_review_actions(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $version = $this->version('submitted');

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSee('Read only — this participant is not assigned to you.')
            ->assertDontSee('Save Review')
            ->assertDontSee('submission-review-form')
            ->assertDontSee('Start Review')
            ->assertDontSee('name="decision"', false);
    }

    public function test_a_rejected_review_returns_to_the_page_with_the_errors_and_the_old_comments(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('submitted');

        $this->actingAs($coach)
            ->from(route('coach.reviews.show', $version))
            ->followingRedirects()
            ->post(route('coach.reviews.store', $version), ['decision' => 'maybe', 'comments' => 'Keep this draft'])
            ->assertOk()
            ->assertSeeInOrder([
                'role="alert">The selected decision is invalid.</div>',
                'id="submission-review-form"',
                'role="alert">The selected decision is invalid.</p>',
                'Keep this draft</textarea>',
            ], false);

        $this->assertDatabaseCount('submission_reviews', 0);
    }

    public function test_week_four_interview_shows_the_official_questionnaire_instead_of_the_activity_details(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('submitted', [], weekNumber: 4, position: 2);
        $firstQuestion = config('training.week_four_interview_questions')[1];

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'Participant Submission',
                'Official AI Proficiency Interview Questionnaire',
                'href="'.route('coach.week-four.questionnaire').'"', '>Open full questionnaire</a>',
                'Q1 · '.$firstQuestion['title'], $firstQuestion['prompt'],
                'Evidence', 'No evidence attached.',
            ])
            ->assertDontSee('Submitted Activity Details');
    }

    public function test_submission_field_labels_come_from_the_loaded_activity_requirements(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion('submitted', ['feature_area' => 'Checkout']);
        ActivityRequirement::factory()->for($version->submission->activity)->create([
            'requirement_type' => 'submission_field',
            'configuration' => ['key' => 'feature_area', 'label' => 'Product Area'],
        ]);

        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSeeInOrder(['Product Area', 'Checkout']);
        DB::disableQueryLog();

        $requirementQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "activity_requirements"'));
        $this->assertCount(1, $requirementQueries);
    }

    public function test_review_page_query_count_does_not_grow_with_evidence_reviews_and_interactions(): void
    {
        $this->assertSame($this->reviewPageQueryCount(1), $this->reviewPageQueryCount(3));
    }

    /**
     * A coach assigned to the participant of a new version whose submission has the given status.
     *
     * @param  array<string, mixed>  $fieldValues
     * @return array{0: User, 1: SubmissionVersion}
     */
    private function assignedCoachWithVersion(string $submissionStatus, array $fieldValues = [], int $weekNumber = 2, int $position = 1): array
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $version = $this->version($submissionStatus, $fieldValues, $weekNumber, $position);
        $coach->assignedParticipants()->attach($version->submission->user_id);

        return [$coach, $version];
    }

    /**
     * A submitted version of "Exploratory charter" by Pat Participant whose submission has the given status.
     *
     * @param  array<string, mixed>  $fieldValues
     */
    private function version(string $submissionStatus, array $fieldValues = [], int $weekNumber = 2, int $position = 1): SubmissionVersion
    {
        $activity = Activity::factory()
            ->for(TrainingWeek::factory()->state(['week_number' => $weekNumber]), 'week')
            ->create(['title' => 'Exploratory charter', 'position' => $position]);
        $submission = ActivitySubmission::factory()
            ->for($activity)
            ->for(User::factory()->state(['name' => 'Pat Participant']), 'participant')
            ->create(['status' => $submissionStatus, 'submitted_at' => now()]);

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => $fieldValues, 'submitted_at' => now()]);
    }

    /**
     * Create a version with the given number of fields, interactions, evidence items and reviews and count the queries
     * of an assigned coach's review page request after a warm-up request.
     */
    private function reviewPageQueryCount(int $itemCount): int
    {
        $fieldValues = collect(range(1, $itemCount))->mapWithKeys(fn (int $index): array => ["field_{$index}" => "Answer {$index}"])->all();
        [$coach, $version] = $this->assignedCoachWithVersion('submitted', $fieldValues);

        foreach (range(1, $itemCount) as $index) {
            SubmissionInteraction::create(['submission_version_id' => $version->id, 'sequence' => $index, 'prompt' => "Prompt {$index}", 'output' => "Output {$index}"]);
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->create();
            SubmissionReview::factory()->for($version, 'submissionVersion')->create(['reviewed_at' => now()->subMinutes($index)]);
        }

        $url = route('coach.reviews.show', $version);
        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee('Current Review');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
