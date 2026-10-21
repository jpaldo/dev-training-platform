<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Support\FieldLimits;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubmissionFormPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('mainActionStates')]
    public function test_single_main_action_matches_the_submission_state(string $submissionStatus, string $versionStatus, string $actionLabel, string $actionRoute): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $submissionStatus]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $versionStatus]);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div id="submission-main-action"[^>]*>(.*?)<\/div>/s', $html, $mainAction));
        $this->assertStringContainsString($actionLabel, $mainAction[1]);
        $this->assertStringContainsString('action="'.route($actionRoute, $actionRoute === 'training.submission.submit' ? [$week, $activity, $version] : [$week, $activity]).'"', $mainAction[1]);
        $this->assertSame(1, substr_count($html, $actionLabel));
        $this->assertSame(1, substr_count($html, 'data-final-submit-form>') + substr_count($html, 'data-revise-submission>'));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function mainActionStates(): array
    {
        return [
            'draft' => ['draft', 'draft', 'Submit Activity', 'training.submission.submit'],
            'needs revision' => ['needs_revision', 'submitted', 'Revise submission', 'training.submission.revise'],
        ];
    }

    public function test_submitted_version_has_no_main_action_or_editable_form(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('This submitted version is immutable.')
            ->assertDontSee('submission-main-action', false)
            ->assertDontSee('data-final-submit-form>', false)
            ->assertDontSee('data-revise-submission>', false)
            ->assertDontSee('data-submission-form>', false);
    }

    public function test_fields_render_under_their_section_headings_in_configured_order(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $this->submissionField($activity, 1, ['key' => 'tool_name', 'label' => 'Tool name', 'type' => 'text', 'section' => 'artifact_context']);
        $this->submissionField($activity, 2, ['key' => 'finding', 'label' => 'Finding summary', 'type' => 'textarea', 'required' => true]);
        $this->submissionField($activity, 3, ['key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'options' => ['Accept', 'Reject']]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['1. AI / ARTIFACT CONTEXT', 'Tool name', '2. YOUR QA WORK', 'Finding summary *', 'Decision', 'Participant note (optional)'])
            ->assertSee('id="tool_name" type="text" name="field_values[tool_name]" value="" maxlength="'.FieldLimits::TEXT.'"', false)
            ->assertSee('name="field_values[finding]" rows="8" maxlength="'.FieldLimits::TEXTAREA.'" required', false)
            ->assertSee('<option value="Reject"', false);
    }

    public function test_each_evidence_requirement_renders_the_upload_form_for_its_type(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        $linkRequirement = null;
        foreach (['text', 'link', 'file'] as $position => $evidenceType) {
            $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity))->create(['evidence_type' => $evidenceType, 'position' => $position]);
            $linkRequirement = $evidenceType === 'link' ? $requirement : $linkRequirement;
        }
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($linkRequirement, 'evidenceRequirement')->create(['evidence_type' => 'link', 'content' => null, 'url' => 'https://example.com/evidence']);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-evidence-form>'));
        $this->assertSame(1, substr_count($html, 'name="content"'));
        $this->assertSame(1, substr_count($html, 'name="url" type="url"'));
        $this->assertSame(1, substr_count($html, 'name="files[]" type="file" required'));
        $this->assertSame(3, substr_count($html, 'name="files[]" type="file"'));
        $this->assertStringContainsString('Save evidence text', $html);
        $this->assertStringContainsString('Save evidence link', $html);
        $this->assertStringContainsString('href="https://example.com/evidence" target="_blank" rel="noopener noreferrer">Open evidence link</a>', $html);
    }

    public function test_peer_interview_progress_reflects_partner_answers_and_practice_questions(): void
    {
        $participant = User::factory()->create();
        $partner = User::factory()->create(['name' => 'Peer Partner']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 4, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 2]);
        WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $partner->id), 'participant_two_id' => max($participant->id, $partner->id), 'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => 'active']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft', 'field_values' => [
            'about_you' => 'QA lead for payments.',
            'practice_questions' => array_fill_keys(range(1, 15), 'How do you validate AI output?'),
        ]]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Partner assigned <span class="sr-only">(complete)</span>',
                'About You <span class="sr-only">(complete)</span>',
                'My practice questions: 15 / 15 <span class="sr-only">(complete)</span>',
                'Partner questions answered: 0 / 15 <span class="sr-only">(incomplete)</span>',
                'Feedback given: 0 / 2 required <span class="sr-only">(incomplete)</span>',
                'Feedback received: 0 / 2 required <span class="sr-only">(incomplete)</span>',
                "PEER PARTNER'S PRACTICE QUESTIONS FOR ME",
            ], false);
    }

    public function test_submission_form_query_count_does_not_grow_with_fields_evidence_and_versions(): void
    {
        $this->assertSame($this->submissionFormQueryCount(1), $this->submissionFormQueryCount(3));
    }

    /**
     * Build an activity with the given number of submission fields, evidence requirements with evidence on the current
     * draft, and earlier reviewed versions, and count the queries of a submission form request after a warm-up request.
     */
    private function submissionFormQueryCount(int $rows): int
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        foreach (range(1, $rows) as $position) {
            $earlier = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $position, 'status' => 'submitted', 'submitted_at' => now()]);
            SubmissionReview::factory()->create(['submission_version_id' => $earlier->id, 'decision' => 'needs_revision']);
        }
        $draft = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $rows + 1, 'status' => 'draft']);

        foreach (range(1, $rows) as $position) {
            $this->submissionField($activity, $position, ['key' => 'answer_'.$position, 'label' => 'Answer '.$position, 'type' => 'textarea']);
            $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity))->create(['position' => $position]);
            SubmissionEvidence::factory()->for($draft, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
        }

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->assertSee('Answer '.$rows)->assertSee('Version '.($rows + 1));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Add a submission field requirement with the given configuration to the activity.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function submissionField(Activity $activity, int $position, array $configuration): void
    {
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => $position, 'configuration' => $configuration]);
    }

    /**
     * A checked-in manual participant and a Week 2 activity that is not the Validation Checklist.
     *
     * @return array{User, TrainingWeek, Activity}
     */
    private function activityFixture(): array
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 2]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $week, $activity];
    }
}
