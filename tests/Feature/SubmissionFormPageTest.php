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

    public function test_length_limited_fields_use_the_shared_character_counter_and_markdown_fields_without_a_page_script(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $this->submissionField($activity, 1, ['key' => 'tool_name', 'label' => 'Tool name', 'type' => 'text']);
        $this->submissionField($activity, 2, ['key' => 'finding', 'label' => 'Finding summary', 'type' => 'textarea']);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'data-character-counter>', '>Tool name</label>', 'maxlength="'.FieldLimits::TEXT.'"', 'data-char-count></span>',
                'data-character-counter>', '>Finding summary</label>', 'maxlength="'.FieldLimits::TEXTAREA.'"', 'data-char-count></span>',
                'data-markdown-field data-preview-url="'.route('markdown.preview').'"', 'id="participant_note"',
            ], false)
            ->assertDontSee('data-field>', false)
            ->assertDontSee("querySelectorAll('[data-field]')", false)
            ->assertDontSee('markdown-preview.js', false);
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

    public function test_a_week_four_activity_two_opens_the_standard_form_without_the_peer_interview(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 4, position: 2);
        $this->submissionField($activity, 1, ['key' => 'interview_notes', 'label' => 'Interview notes', 'type' => 'textarea', 'required' => true]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('action="'.route('training.submission.draft', [$week, $activity]).'" data-submission-form>', false)
            ->assertSee('name="field_values[interview_notes]"', false)
            ->assertSee('MENTOR MOCK INTERVIEW')
            ->assertDontSee('PEER INTERVIEW PROGRESS')
            ->assertDontSee('Partner Assignment Pending')
            ->assertDontSee('name="field_values[practice_questions]', false)
            ->assertDontSee('name="field_values[partner_user_id]"', false);
    }

    public function test_a_later_week_three_activity_opens_the_standard_form_before_earlier_activities_are_submitted(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 3, position: 2);
        Activity::factory()->for($week, 'week')->create(['position' => 1]);
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => 1, 'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => true]]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('action="'.route('training.submission.draft', [$week, $activity]).'" data-submission-form>', false)
            ->assertSee('name="field_values[finding]"', false)
            ->assertDontSee('WEEK 3 WORKSPACE');
    }

    public function test_version_history_offers_a_read_only_view_of_earlier_submitted_versions_only(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $submitted = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted', 'submitted_at' => now()]);
        $leftoverDraft = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 3, 'status' => 'draft']);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-version-row'));
        $this->assertStringContainsString('href="'.route('training.submission.show', [$week, $activity, $submitted]).'"', $html);
        $this->assertStringNotContainsString(route('training.submission.show', [$week, $activity, $leftoverDraft]), $html);
        $this->assertSame(1, substr_count($html, 'Read only'));
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
     * A checked-in manual participant and an activity in the given week; by default a Week 2 activity that is not the
     * Validation Checklist.
     *
     * @return array{User, TrainingWeek, Activity}
     */
    private function activityFixture(int $weekNumber = 2, int $position = 2): array
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => $weekNumber, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => $position]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $week, $activity];
    }
}
