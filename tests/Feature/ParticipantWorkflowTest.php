<?php

namespace Tests\Feature;

use App\Jobs\ExportActivityJob;
use App\Jobs\SyncTrainingDataJob;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivityScore;
use App\Models\ActivityScoreDimension;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participant_submission_page_renders_with_the_vite_manifest(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        ActivityRequirement::factory()->for($activity)->create([
            'requirement_type' => 'submission_field',
            'position' => 1,
            'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => false],
        ]);

        $this->assertFileExists(public_path('build/manifest.json'));
        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week2, $activity]))
            ->assertOk()
            ->assertSee('Finding');
    }

    public function test_editing_without_an_open_attendance_session_guides_participant_to_check_in(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week2, $activity]))
            ->assertRedirect(route('participant.attendance.index'))
            ->assertSessionHasErrors(['attendance' => 'Please check in before continuing this activity.']);

        $this->actingAs($participant)
            ->get(route('participant.attendance.index'))
            ->assertOk()
            ->assertSeeText('CHECK IN');
    }

    public function test_participant_can_view_an_available_activity_and_create_edit_submit_a_draft(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => 1, 'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => true]]);
        $deliverable = ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable', 'position' => 10]);
        $evidenceRequirement = EvidenceRequirement::factory()->for($deliverable)->create(['evidence_type' => 'text', 'is_required' => true]);

        $this->actingAs($participant)->get(route('training.activities.show', [$week2, $activity]))->assertOk()->assertSee('Finding');
        $this->actingAs($participant)->post(route('training.submission.draft', [$week2, $activity]), ['field_values' => ['finding' => 'Initial finding'], 'status' => 'approved'])->assertRedirect();
        $submission = ActivitySubmission::query()->firstOrFail();
        $version = $submission->versions()->firstOrFail();
        $this->assertSame('draft', $submission->status);
        $this->actingAs($participant)->post(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $evidenceRequirement->id, 'content' => 'Observed in the real run.'])->assertRedirect();
        $this->actingAs($participant)->post(route('training.submission.submit', [$week2, $activity, $version]))->assertRedirect();
        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'status' => 'submitted']);
    }

    public function test_text_evidence_does_not_satisfy_a_required_file_evidence_requirement(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => 1, 'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => false]]);
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file', 'is_required' => true, 'minimum_count' => 1]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_requirement_id' => $requirement->id, 'evidence_type' => 'text', 'content' => 'proof.png', 'disk' => null, 'path' => null, 'original_filename' => null]);

        $this->actingAs($participant)->post(route('training.submission.submit', [$week2, $activity, $version]))
            ->assertSessionHasErrors('evidence');
        $this->assertSame('draft', $version->fresh()->status);
    }

    public function test_text_and_uploaded_evidence_are_clearly_distinguished_in_submission_view(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'text', 'content' => 'proof-1.png']);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/proof-2.png';
        Storage::disk('local')->put($path, 'image');
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'proof-2.png']);

        $html = $this->actingAs($participant)->get(route('training.submission.show', [$week2, $activity, $version]))->assertOk()->getContent();

        $this->assertStringContainsString('Type: Text', $html);
        $this->assertStringContainsString('Text evidence', $html);
        $this->assertStringContainsString('Type: Uploaded File', $html);
        $this->assertStringContainsString('data-evidence-preview-open', $html);
        $this->assertStringContainsString('data-evidence-preview-image', $html);
        $this->assertStringNotContainsString('>View</a>', $html);
        $this->assertStringContainsString('>Download</a>', $html);
    }

    public function test_image_evidence_view_uses_shared_modal_for_supported_image_types(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);

        foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $extension) {
            $path = 'evidence/'.$participant->id.'/'.$version->id.'/proof.'.$extension;
            Storage::disk('local')->put($path, 'image');
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->create([
                'evidence_type' => 'file',
                'disk' => 'local',
                'path' => $path,
                'original_filename' => 'proof.'.$extension,
                'mime_type' => 'image/'.($extension === 'jpg' || $extension === 'jpeg' ? 'jpeg' : $extension),
            ]);
        }

        $html = $this->actingAs($participant)->get(route('training.submission.show', [$week2, $activity, $version]))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'data-evidence-preview-open'));
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'data-evidence-preview-image'));
        $this->assertStringContainsString('training/evidence/', $html);
        $this->assertStringContainsString('Download', $html);
    }

    public function test_text_evidence_requirement_also_exposes_artifact_upload_and_persists_private_file(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'text', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))->assertOk()->assertSee('type="file"', false)->assertSee('multipart/form-data', false)->assertSee('data-evidence-file', false)->assertDontSee('Save draft')->assertDontSee('Add evidence');
        $this->actingAs($participant)->post(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $requirement->id, 'file' => UploadedFile::fake()->create('proof.png', 20, 'image/png')])->assertRedirect();
        $evidence = SubmissionEvidence::query()->firstOrFail();
        $this->assertSame('file', $evidence->evidence_type);
        $this->assertTrue(Storage::disk('local')->exists($evidence->path));
    }

    public function test_auto_save_returns_json_and_preserves_editable_draft_values(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => 1, 'configuration' => ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'required' => false]]);

        $response = $this->actingAs($participant)->postJson(route('training.submission.draft', [$week2, $activity]), [
            'field_values' => ['finding' => 'Saved without a draft button'],
            'participant_note' => 'Background save',
        ]);

        $response->assertOk()->assertJson(['saved' => true, 'status' => 'Saved']);
        $version = SubmissionVersion::query()->firstOrFail();
        $this->assertSame('draft', $version->status);
        $this->assertSame('Saved without a draft button', $version->field_values['finding']);
        $this->actingAs($participant)->postJson(route('training.submission.draft', [$week2, $activity]), ['field_values' => ['finding' => 'Updated quietly']])->assertOk();
        $this->assertDatabaseCount('activity_submissions', 1);
        $this->assertSame('Updated quietly', $version->fresh()->field_values['finding']);
    }

    public function test_uploaded_evidence_returns_json_and_is_immediately_attached(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $response = $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('auto-attached.png', 20, 'image/png'),
        ]);

        $response->assertOk()->assertJson(['uploaded' => true, 'status' => 'Uploaded']);
        $this->assertDatabaseHas('submission_evidence', ['submission_version_id' => $version->id, 'original_filename' => 'auto-attached.png']);
    }

    public function test_multiple_evidence_files_upload_and_persist_independently(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'files' => [UploadedFile::fake()->create('first.png', 20, 'image/png'), UploadedFile::fake()->create('second.png', 20, 'image/png')],
        ])->assertOk()->assertJsonCount(2, 'evidence_ids');

        $this->assertSame(2, SubmissionEvidence::query()->where('submission_version_id', $version->id)->count());
        $this->assertDatabaseHas('submission_evidence', ['original_filename' => 'first.png']);
        $this->assertDatabaseHas('submission_evidence', ['original_filename' => 'second.png']);
    }

    public function test_embedded_weekly_video_picker_uses_broad_video_guidance(): void
    {
        [$participant, $week2, $activity] = $this->videoEvidenceFixture();

        $html = $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week2, $activity]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('accept="video/*"', $html);
        $this->assertStringContainsString('Upload a video file · maximum 350 MB', $html);
        $this->assertStringContainsString('Common video formats are supported.', $html);
        $this->assertStringNotContainsString('video/mp4,video/quicktime,video/webm,video/x-m4v', $html);
    }

    #[DataProvider('embeddedVideoProvider')]
    public function test_embedded_weekly_video_accepts_common_and_broader_formats(string $extension, string $mime): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity, $requirement, $version] = $this->videoEvidenceFixture();

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('recording.'.$extension, 20, $mime),
        ])->assertOk()->assertJson(['uploaded' => true]);

        $this->assertDatabaseHas('submission_evidence', ['submission_version_id' => $version->id, 'original_filename' => 'recording.'.$extension]);
    }

    public static function embeddedVideoProvider(): array
    {
        return [
            'mp4' => ['mp4', 'video/mp4'],
            'webm' => ['webm', 'video/webm'],
            'avi' => ['avi', 'video/x-msvideo'],
        ];
    }

    public function test_embedded_weekly_video_rejects_renamed_non_video_and_unsupported_extensions(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity, $requirement, $version] = $this->videoEvidenceFixture();

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('renamed.mp4', 20, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('recording.exe', 20, 'video/mp4'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_embedded_weekly_video_enforces_350_mb_limit_and_authorization(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity, $requirement, $version] = $this->videoEvidenceFixture();

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('too-large.mp4', 358401, 'video/mp4'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
                'evidence_requirement_id' => $requirement->id,
                'file' => UploadedFile::fake()->create('blocked.mp4', 20, 'video/mp4'),
            ])->assertForbidden();
    }

    public function test_evidence_file_size_boundary_is_300_mb_per_file(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $requirement->id, 'file' => UploadedFile::fake()->create('boundary.png', 307200, 'image/png')])->assertOk();
        $this->assertDatabaseHas('submission_evidence', ['original_filename' => 'boundary.png']);

        $version->refresh();
        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $requirement->id, 'file' => UploadedFile::fake()->create('too-large.png', 307201, 'image/png')])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    #[DataProvider('generalEvidenceFileProvider')]
    public function test_common_general_evidence_formats_upload_successfully(string $extension, string $mime): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $this->actingAs($participant)->postJson(route('training.evidence.store', [$week2, $activity, $version]), [
            'evidence_requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('evidence.'.$extension, 20, $mime),
        ])->assertOk()->assertJson(['uploaded' => true]);
    }

    public static function generalEvidenceFileProvider(): array
    {
        return [
            ['txt', 'text/plain'], ['md', 'text/markdown'], ['csv', 'text/csv'], ['json', 'application/json'], ['log', 'text/plain'],
            ['xls', 'application/vnd.ms-excel'], ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['pdf', 'application/pdf'], ['doc', 'application/msword'], ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['png', 'image/png'], ['jpg', 'image/jpeg'], ['jpeg', 'image/jpeg'], ['gif', 'image/gif'], ['webp', 'image/webp'],
            ['mp4', 'video/mp4'], ['mov', 'video/quicktime'], ['webm', 'video/webm'], ['m4v', 'video/x-m4v'],
        ];
    }

    public function test_evidence_upload_script_captures_file_before_disabling_input(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))->assertOk()->getContent();
        $formDataPosition = strpos($html, 'const body = new FormData();');
        $selectedPosition = strpos($html, 'const selected = [...file.files];');
        $disablePosition = strpos($html, 'file.disabled = true;');

        $this->assertNotFalse($formDataPosition);
        $this->assertNotFalse($disablePosition);
        $this->assertLessThan($disablePosition, $selectedPosition);
        $this->assertStringContainsString("body.append('files[]', item, item.name);", $html);
        $this->assertStringContainsString('body })', $html);
        $this->assertStringContainsString('payload?.errors', $html);
        $this->assertNotNull($requirement);
    }

    public function test_submitted_versions_are_immutable_and_revision_creates_a_new_version(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now(), 'field_values' => ['finding' => 'Original']]);
        $this->actingAs($participant)->post(route('training.submission.draft', [$week2, $activity]), ['field_values' => ['finding' => 'Tampered']])->assertSessionHasErrors('submission');
        $this->assertSame(1, $submission->fresh()->versions()->count());
        $submission->update(['status' => 'needs_revision']);
        $this->actingAs($participant)->post(route('training.submission.draft', [$week2, $activity]), ['field_values' => ['finding' => 'Revised']])->assertRedirect();
        $this->assertSame(2, $submission->fresh()->versions()->count());
        $this->assertSame('Original', $version->fresh()->field_values['finding']);
    }

    public function test_needs_revision_has_participant_revision_action_and_keeps_previous_version_immutable(): void
    {
        Queue::fake();
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now(), 'field_values' => ['finding' => 'Original']]);

        $this->actingAs($participant)->get(route('training.activities.show', [$week2, $activity]))->assertOk()->assertSee('Revise submission');
        $this->actingAs($participant)->post(route('training.submission.revise', [$week2, $activity]))->assertRedirect();
        $this->assertSame(2, $submission->fresh()->versions()->count());
        $this->assertSame('submitted', $version->fresh()->status);
        $this->assertSame('Original', $version->fresh()->field_values['finding']);
        $this->assertSame('draft', $submission->fresh()->versions()->latest('version_number')->first()->status);
        Queue::assertPushed(ExportActivityJob::class);

        $this->actingAs($participant)->post(route('training.submission.revise', [$week2, $activity]))->assertRedirect();
        $this->assertSame(2, $submission->fresh()->versions()->count());
    }

    public function test_revision_action_is_rendered_as_a_csrf_protected_post_form_not_a_get_link(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $action = route('training.submission.revise', [$week2, $activity]);
        $response = $this->actingAs($participant)->get(route('training.activities.show', [$week2, $activity]));

        $response->assertOk()
            ->assertSee('method="POST" action="'.$action.'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('href="'.$action.'"', false);
        $this->assertSame('submitted', $version->fresh()->status);
    }

    public function test_get_to_revision_endpoint_is_rejected_and_cannot_bypass_post_semantics(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($participant)
            ->get(route('training.submission.revise', [$week2, $activity]))
            ->assertStatus(405);
    }

    public function test_needs_revision_feedback_is_prominent_and_safe_markdown_is_rendered(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version->reviews()->create([
            'reviewer_id' => User::factory()->create(['role' => 'coach'])->id,
            'decision' => 'needs_revision',
            'comments' => "## Issues Found\n\n- Correct the expected result\n\n<script>alert('x')</script>",
            'reviewed_at' => now(),
        ]);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Current status:'), strpos($html, 'Coach Feedback'));
        $this->assertStringContainsString('<h3>Issues Found</h3>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function test_activity_page_renders_safe_markdown_for_coach_feedback(): void
    {
        [$participant, $week, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version->reviews()->create([
            'reviewer_id' => User::factory()->create(['role' => 'coach'])->id,
            'decision' => 'needs_revision',
            'comments' => "## Issues Found\n\n- Correct the expected result\n\n**Do this now**\n\n<script>alert('x')</script>",
            'reviewed_at' => now(),
        ]);

        $html = $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))->assertOk()->getContent();

        $this->assertStringContainsString('<h3>Issues Found</h3>', $html);
        $this->assertStringContainsString('<li>Correct the expected result</li>', $html);
        $this->assertStringContainsString('<strong>Do this now</strong>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert', $html);
    }

    public function test_submission_page_renders_version_history_rows(): void
    {
        [$participant, $week, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted', 'submitted_at' => now()->subDays(2)]);
        $current = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'needs_revision', 'submitted_at' => now()]);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 3, 'status' => 'draft']);

        $html = $this->actingAs($participant)->get(route('training.submission.show', [$week, $activity, $current]))->assertOk()->getContent();

        $this->assertStringContainsString('Submission history', $html);
        $this->assertStringContainsString('Version 2', $html);
        $this->assertStringContainsString('Version 1', $html);
        $this->assertStringNotContainsString('Version 3', $html);
        $this->assertStringContainsString('class="pill version-status needs_revision"', $html);
        $this->assertSame(1, substr_count($html, 'class="pill version-current"'));
        $this->assertSame(1, substr_count($html, '>View</a>'));
        $this->assertSame(1, substr_count($html, 'Read only'));
    }

    public function test_edit_page_renders_version_history_rows(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        $submitted = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted', 'submitted_at' => now()->subDays(2)]);
        $draft = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'draft']);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))->assertOk()->getContent();

        $this->assertStringContainsString('Version history', $html);
        $this->assertSame(2, substr_count($html, 'class="version-history-row'));
        $this->assertSame(1, preg_match_all('/class="version-history-row\s*current/', $html));
        $this->assertSame(1, substr_count($html, 'class="pill version-current"'));
        $this->assertStringContainsString('class="pill version-status draft"', $html);
        $this->assertStringContainsString('Version 2', $html);
        $this->assertStringContainsString('Version 1', $html);
        $this->assertSame(1, substr_count($html, route('training.submission.show', [$week2, $activity, $submitted])));
        $this->assertStringNotContainsString('href="'.route('training.submission.show', [$week2, $activity, $draft]).'"', $html);
        $this->assertSame(1, substr_count($html, 'Read only'));
    }

    public function test_attempt_history_makes_failed_attempt_and_active_retry_distinct(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $failed = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['attempt_number' => 1, 'status' => 'failed', 'submitted_at' => now()->subDay()]);
        $failedVersion = SubmissionVersion::factory()->for($failed, 'submission')->create(['status' => 'failed', 'submitted_at' => now()->subDay()]);
        $failedVersion->reviews()->create(['reviewer_id' => User::factory()->create(['role' => 'coach'])->id, 'decision' => 'rejected', 'comments' => 'Please correct the expected result.', 'reviewed_at' => now()->subDay()]);
        $retry = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['attempt_number' => 2, 'status' => 'draft']);
        $retry->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => []]);

        $this->actingAs($participant)->get(route('training.activities.show', [$week2, $activity]))
            ->assertOk()
            ->assertSee('Attempt 1')
            ->assertSee('FAILED · CLOSED')
            ->assertSee('This attempt was not accepted by your coach and is now closed.')
            ->assertSee('Please correct the expected result.')
            ->assertSee('Attempt 2')
            ->assertSee('DRAFT · IN PROGRESS')
            ->assertSee('Your work is saved as a draft.')
            ->assertSee('Continue Attempt 2 →')
            ->assertDontSee('Optional reason for requesting review');
    }

    public function test_failed_attempt_without_retry_exposes_review_request_and_no_fake_feedback(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $failed = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['attempt_number' => 1, 'status' => 'failed']);
        $failed->versions()->create(['version_number' => 1, 'status' => 'failed', 'field_values' => []]);

        $this->actingAs($participant)->get(route('training.activities.show', [$week2, $activity]))
            ->assertOk()
            ->assertSee('FAILED · CLOSED')
            ->assertSee('Request Review')
            ->assertDontSee('Coach feedback')
            ->assertDontSee('No coach feedback was provided.');
    }

    public function test_invalid_evidence_files_are_rejected_and_other_participants_cannot_download_evidence(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $other = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($other, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create();
        $requirement = EvidenceRequirement::factory()->create(['evidence_type' => 'file']);
        $path = 'evidence/'.$other->id.'/'.$version->id.'/proof.txt';
        Storage::disk('local')->put($path, 'proof');
        $evidence = SubmissionEvidence::factory()->for($version)->create(['evidence_requirement_id' => $requirement->id, 'disk' => 'local', 'path' => $path, 'original_filename' => 'proof.txt']);
        $this->actingAs($participant)->get(route('training.evidence.download', $evidence))->assertForbidden();
        $draftSubmission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $draft = SubmissionVersion::factory()->for($draftSubmission, 'submission')->create();
        $this->actingAs($participant)->post(route('training.evidence.store', [$week2, $activity, $draft]), ['evidence_requirement_id' => $requirement->id, 'file' => UploadedFile::fake()->create('unsafe.exe', 20, 'application/octet-stream')])->assertSessionHasErrors('file');
    }

    public function test_participant_sees_only_finalized_score_and_feedback_for_own_submission(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $other = User::factory()->create(['training_path' => 'manual']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'approved']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'approved']);
        $coach = User::factory()->create(['role' => 'coach']);
        $score = ActivityScore::create(['submission_version_id' => $version->id, 'scorer_id' => $coach->id, 'status' => 'published', 'total_score' => 88, 'performance_band' => 'Proficient', 'overall_feedback' => 'Excellent final work.']);
        ActivityScoreDimension::create(['activity_score_id' => $score->id, 'dimension' => 'D1', 'points' => 20, 'evidence_reasoning' => 'Clear reasoning.']);
        $version->reviews()->create(['reviewer_id' => $coach->id, 'decision' => 'approved', 'comments' => 'Final mentor feedback.', 'reviewed_at' => now()]);

        $this->actingAs($participant)->get(route('training.submission.show', [$week2, $activity, $version]))
            ->assertOk()
            ->assertSee('Final score:')
            ->assertSee('Excellent final work.')
            ->assertSee('Clear reasoning.')
            ->assertDontSee('AI suggestions')
            ->assertDontSee('internal reasoning');

        $otherSubmission = ActivitySubmission::factory()->for($other, 'participant')->for($activity)->create();
        $otherVersion = SubmissionVersion::factory()->for($otherSubmission, 'submission')->create(['status' => 'approved']);
        $this->actingAs($participant)->get(route('training.submission.show', [$week2, $activity, $otherVersion]))->assertNotFound();
    }

    public function test_authorized_evidence_view_is_inline_and_download_is_attachment(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/proof.png';
        Storage::disk('local')->put($path, 'image');
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'proof.png', 'mime_type' => 'image/png']);

        $this->actingAs($participant)->get(route('training.evidence.view', $evidence))->assertOk()->assertHeader('Content-Disposition', 'inline; filename="proof.png"')->assertHeader('Content-Type', 'image/png');
        $this->actingAs($participant)->get(route('training.evidence.preview', $evidence))->assertOk()->assertJsonPath('kind', 'text');
        $this->actingAs($participant)->get(route('training.evidence.download', $evidence))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=proof.png');
        $otherParticipant = User::factory()->create(['role' => 'participant']);
        $this->actingAs($otherParticipant)->get(route('training.evidence.view', $evidence))->assertForbidden();
        $this->actingAs($otherParticipant)->get(route('training.evidence.preview', $evidence))->assertForbidden();
    }

    public function test_docx_evidence_view_uses_a_bounded_local_preview(): void
    {
        Storage::fake('local');
        [$participant, , $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $temporary = tempnam(sys_get_temp_dir(), 'qa-docx-');
        $archive = new \ZipArchive;
        $archive->open($temporary);
        $archive->addFromString('word/document.xml', '<document><body><p>Private preview text</p></body></document>');
        $archive->close();
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/notes.docx';
        Storage::disk('local')->put($path, file_get_contents($temporary));
        unlink($temporary);
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'notes.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

        $this->actingAs($participant)->get(route('training.evidence.view', $evidence))->assertOk()->assertSee('Private preview text')->assertSee('Download evidence');
        $this->actingAs($participant)->get(route('training.evidence.preview', $evidence))->assertOk()->assertJsonPath('kind', 'text')->assertJsonPath('content', 'Private preview text');
    }

    public function test_xlsx_evidence_view_uses_a_bounded_local_preview(): void
    {
        Storage::fake('local');
        [$participant, , $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $temporary = tempnam(sys_get_temp_dir(), 'qa-xlsx-');
        $archive = new \ZipArchive;
        $archive->open($temporary);
        $archive->addFromString('xl/sharedStrings.xml', '<sst><si><t>Spreadsheet preview text</t></si></sst>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>');
        $archive->close();
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/report.xlsx';
        Storage::disk('local')->put($path, file_get_contents($temporary));
        unlink($temporary);
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'report.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

        $this->actingAs($participant)->get(route('training.evidence.view', $evidence))->assertOk()->assertSee('Spreadsheet preview text')->assertSee('Download evidence');
        $this->actingAs($participant)->get(route('training.evidence.preview', $evidence))->assertOk()->assertJsonPath('kind', 'workbook')->assertJsonPath('headers.0', 'Spreadsheet preview text');
    }

    public function test_csv_evidence_preview_returns_escaped_bounded_rows(): void
    {
        Storage::fake('local');
        [$participant, , $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/report.csv';
        Storage::disk('local')->put($path, "Name,Comment\nAlice,\"<script>alert(1)</script>\"\n");
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'report.csv', 'mime_type' => 'text/csv']);

        $this->actingAs($participant)->get(route('training.evidence.preview', $evidence))
            ->assertOk()
            ->assertJsonPath('kind', 'table')
            ->assertJsonPath('headers.0', 'Name')
            ->assertJsonPath('rows.0.1', '<script>alert(1)</script>');
    }

    public function test_evidence_requires_the_content_url_or_file_its_type_needs(): void
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $deliverable = ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        $expectedErrors = [
            'text' => ['content' => 'Text evidence is required.'],
            'link' => ['url' => 'A URL is required for link evidence.'],
            'screenshot' => ['file' => 'A file is required for this evidence.'],
        ];

        foreach ($expectedErrors as $type => $error) {
            $requirement = EvidenceRequirement::factory()->for($deliverable)->create(['evidence_type' => $type]);

            $this->actingAs($participant)
                ->post(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $requirement->id])
                ->assertSessionHasErrors($error);
        }

        $this->assertDatabaseCount('submission_evidence', 0);
    }

    public function test_link_evidence_stores_only_the_url_and_queues_the_activity_export(): void
    {
        Queue::fake();
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'link']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $this->actingAs($participant)
            ->post(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $requirement->id, 'url' => 'https://example.test/report', 'content' => 'Ignored text'])
            ->assertRedirect(route('training.submission.edit', [$week2, $activity]))
            ->assertSessionHas('status', 'Evidence added.');

        $evidence = SubmissionEvidence::query()->sole();
        $this->assertSame('link', $evidence->evidence_type);
        $this->assertSame('https://example.test/report', $evidence->url);
        $this->assertNull($evidence->content);
        Queue::assertPushed(ExportActivityJob::class, fn (ExportActivityJob $job): bool => $job->versionId === $version->id);
    }

    public function test_uploaded_file_is_deleted_when_its_evidence_record_cannot_be_created(): void
    {
        Storage::fake('local');
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'file']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        SubmissionEvidence::creating(function (): void {
            throw new \RuntimeException('Evidence could not be saved.');
        });

        $this->actingAs($participant)
            ->postJson(route('training.evidence.store', [$week2, $activity, $version]), ['evidence_requirement_id' => $requirement->id, 'file' => UploadedFile::fake()->create('proof.png', 20, 'image/png')])
            ->assertServerError();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_video_evidence_view_serves_the_stored_file_inline(): void
    {
        Storage::fake('local');
        [$participant, , $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/demo.mp4';
        Storage::disk('local')->put($path, 'video');
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'demo.mp4', 'mime_type' => 'video/mp4']);

        $this->actingAs($participant)->get(route('training.evidence.view', $evidence))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Disposition', 'inline; filename="demo.mp4"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_unreadable_office_evidence_preview_returns_an_error_message(): void
    {
        Storage::fake('local');
        [$participant, , $activity] = $this->trainingFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/broken.xlsx';
        Storage::disk('local')->put($path, 'not a workbook');
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => $path, 'original_filename' => 'broken.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

        $this->actingAs($participant)->get(route('training.evidence.preview', $evidence))
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Unable to preview this evidence.']);
    }

    public function test_participant_daily_logs_are_owned_and_activity_associations_are_checked(): void
    {
        [$participant, , $activity] = $this->trainingFixture();
        $other = User::factory()->create();
        $log = $other->dailyLogs()->create(['activity_id' => $activity->id, 'logged_on' => now()->toDateString(), 'content' => 'Private log']);
        $this->actingAs($participant)->get(route('daily-logs.edit', $log))->assertForbidden();
        $this->actingAs($participant)->post(route('daily-logs.store'), ['activity_id' => $activity->id, 'logged_on' => now()->toDateString(), 'content' => 'My work'])->assertRedirect();
        $this->assertDatabaseHas('daily_logs', ['user_id' => $participant->id, 'content' => 'My work']);
    }

    public function test_daily_log_future_date_is_rejected_server_side(): void
    {
        [$participant, , $activity] = $this->trainingFixture();

        $this->actingAs($participant)->post(route('daily-logs.store'), [
            'activity_id' => $activity->id,
            'logged_on' => now(config('app.timezone', 'Asia/Manila'))->addDay()->toDateString(),
            'content' => 'Future work',
        ])->assertSessionHasErrors('logged_on');
        $this->assertDatabaseMissing('daily_logs', ['user_id' => $participant->id, 'content' => 'Future work']);
    }

    public function test_daily_log_accepts_today_and_past_dates(): void
    {
        [$participant, , $activity] = $this->trainingFixture();
        $timezone = config('app.timezone', 'Asia/Manila');

        foreach ([now($timezone)->toDateString(), now($timezone)->subDay()->toDateString(), now($timezone)->subDays(14)->toDateString()] as $date) {
            $this->actingAs($participant)->post(route('daily-logs.store'), [
                'activity_id' => $activity->id,
                'logged_on' => $date,
                'content' => 'Work on '.$date,
            ])->assertRedirect();
        }

        $this->assertSame(3, $participant->dailyLogs()->count());
    }

    public function test_daily_log_date_picker_defaults_to_manila_today_and_edit_preserves_stored_date(): void
    {
        [$participant, , $activity] = $this->trainingFixture();
        $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();

        $this->actingAs($participant)->get(route('daily-logs.create'))
            ->assertOk()
            ->assertSee('type="date"', false)
            ->assertSee('data-date-picker', false)
            ->assertSee('input.showPicker()', false)
            ->assertSee('value="'.$today.'"', false)
            ->assertSee('max="'.$today.'"', false);

        $storedDate = now(config('app.timezone', 'Asia/Manila'))->subDays(3)->toDateString();
        $log = $participant->dailyLogs()->create(['activity_id' => $activity->id, 'logged_on' => $storedDate, 'content' => 'Existing entry']);
        $this->actingAs($participant)->get(route('daily-logs.edit', $log))
            ->assertOk()
            ->assertSee('data-date-picker', false)
            ->assertSee('value="'.$storedDate.'"', false);
    }

    public function test_daily_log_create_and_manual_export_queue_participant_week_export(): void
    {
        Queue::fake();
        [$participant, , $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('daily-logs.store'), ['activity_id' => $activity->id, 'logged_on' => now()->toDateString(), 'content' => 'Exportable log'])->assertRedirect();
        Queue::assertNotPushed(SyncTrainingDataJob::class);
    }

    public function test_guests_cannot_access_participant_training_pages(): void
    {
        [, $week2] = $this->trainingFixture();

        $this->get(route('training.weeks.show', $week2))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_week_three_and_four_are_server_locked_until_prior_gate_evaluations_pass(): void
    {
        [$participant, $week2] = $this->trainingFixture();
        $week3 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 3, 'position' => 2]);
        $week4 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 4, 'position' => 3]);
        $week3Activity = Activity::factory()->for($week3, 'week')->create();
        $gate2 = TrainingGate::factory()->for($week2, 'week')->create();
        $gate3 = TrainingGate::factory()->for($week3, 'week')->create();
        $this->actingAs($participant)->get(route('training.weeks.show', $week3))->assertForbidden();
        $this->actingAs($participant)->post(route('training.submission.draft', [$week3, $week3Activity]), ['field_values' => []])->assertForbidden();
        GateEvaluation::factory()->for($gate2, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        $this->actingAs($participant)->get(route('training.weeks.show', $week3))->assertOk();
        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertForbidden();
        GateEvaluation::factory()->for($gate3, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertOk();
    }

    public function test_coach_can_open_a_locked_participant_week(): void
    {
        [, $week2] = $this->trainingFixture();
        $week3 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 3, 'position' => 2]);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $this->actingAs($coach)->get(route('training.weeks.show', $week3))->assertOk();
    }

    public function test_manual_and_automation_participants_see_only_their_path_requirements(): void
    {
        [$manual, $week2, $activity] = $this->trainingFixture();
        $automation = User::factory()->create(['training_path' => 'automation']);
        $this->actingAs($manual)->post(route('participant.attendance.check-in'))->assertRedirect();
        $this->actingAs($automation)->post(route('participant.attendance.check-in'))->assertRedirect();
        ActivityRequirement::factory()->for($activity)->create(['participant_path' => 'manual', 'title' => 'Manual-only requirement', 'position' => 1]);
        ActivityRequirement::factory()->for($activity)->create(['participant_path' => 'automation', 'title' => 'Automation-only requirement', 'position' => 2]);

        $this->actingAs($manual)->get(route('training.activities.show', [$week2, $activity]))->assertSee('Manual-only requirement')->assertDontSee('Automation-only requirement');
        $this->actingAs($automation)->get(route('training.activities.show', [$week2, $activity]))->assertSee('Automation-only requirement')->assertDontSee('Manual-only requirement');
    }

    public function test_direct_cross_week_activity_ids_cannot_bypass_progression(): void
    {
        [$participant, $week2] = $this->trainingFixture();
        $week3 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 3, 'position' => 2]);
        $week3Activity = Activity::factory()->for($week3, 'week')->create();
        $this->actingAs($participant)->get(route('training.activities.show', [$week2, $week3Activity]))->assertNotFound();
    }

    #[DataProvider('incompleteStatuses')]
    public function test_incomplete_week_three_statuses_do_not_unlock_week_four(string $status): void
    {
        [$participant, $week2] = $this->trainingFixture();
        $week3 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 3, 'position' => 2]);
        $week4 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 4, 'position' => 3]);
        $gate2 = TrainingGate::factory()->for($week2, 'week')->create();
        $gate3 = TrainingGate::factory()->for($week3, 'week')->create();
        GateEvaluation::factory()->for($gate2, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($gate3, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($participant)->get(route('training.weeks.show', $week4))->assertForbidden();
    }

    #[DataProvider('incompleteStatuses')]
    public function test_incomplete_week_two_statuses_do_not_unlock_week_three(string $status): void
    {
        [$participant, $week2] = $this->trainingFixture();
        $week3 = TrainingWeek::factory()->for($week2->program)->create(['week_number' => 3, 'position' => 2]);
        $gate = TrainingGate::factory()->for($week2, 'week')->create();
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => $status]);

        $this->actingAs($participant)->get(route('training.weeks.show', $week3))->assertForbidden();
    }

    public static function incompleteStatuses(): array
    {
        return ['draft' => ['draft'], 'under review' => ['under_review'], 'needs revision' => ['needs_revision'], 'rejected' => ['failed']];
    }

    private function trainingFixture(): array
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $program = Program::factory()->create();
        $week2 = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week2, 'week')->create(['position' => 1]);

        return [$participant, $week2, $activity];
    }

    private function videoEvidenceFixture(): array
    {
        [$participant, $week2, $activity] = $this->trainingFixture();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'deliverable']))->create(['evidence_type' => 'video', 'is_required' => true]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        return [$participant, $week2, $activity, $requirement, $version];
    }
}
