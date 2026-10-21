<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class EvidencePreviewUnavailablePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function test_office_evidence_shows_the_escaped_bounded_preview_and_the_download_action(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['training_path' => 'manual']);
        $version = $this->submittedVersionFor($participant);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/notes.docx';
        Storage::disk('local')->put($path, $this->docxContents('<p>Checked &lt;script&gt;alert(1)&lt;/script&gt; output</p>'));
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create([
            'evidence_type' => 'file',
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'notes.docx',
            'mime_type' => self::DOCX_MIME,
        ]);

        $response = $this->actingAs($participant)->get(route('training.evidence.view', $evidence))
            ->assertOk()
            ->assertSeeInOrder([
                'EVIDENCE PREVIEW',
                'notes.docx</h1>',
                'A bounded local preview is shown below. The original evidence remains private.',
                'role="region"',
                'aria-label="Evidence preview"',
                '>Checked &lt;script&gt;alert(1)&lt;/script&gt; output</pre>',
                '<strong>Detected type:</strong> '.self::DOCX_MIME,
                'href="'.route('training.evidence.download', $evidence).'"',
                '>Download evidence</a>',
            ], false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('cannot be safely rendered')
            ->assertDontSee('style="', false)
            ->assertDontSee('class="ai"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'from-brand to-brand-deep'));
    }

    public function test_evidence_without_a_preview_explains_why_and_falls_back_to_a_generic_title(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['training_path' => 'manual']);
        $version = $this->submittedVersionFor($participant);
        $path = 'evidence/'.$participant->id.'/'.$version->id.'/bundle.zip';
        Storage::disk('local')->put($path, 'archive');
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create([
            'evidence_type' => 'file',
            'disk' => 'local',
            'path' => $path,
            'original_filename' => null,
            'mime_type' => 'application/zip',
        ]);

        $this->actingAs($participant)->get(route('training.evidence.view', $evidence))
            ->assertOk()
            ->assertSeeInOrder([
                'Uploaded evidence</h1>',
                'This file type cannot be safely rendered in the browser. The evidence remains private and is available through the authorized download.',
                '<strong>Detected type:</strong> application/zip',
                'href="'.route('training.evidence.download', $evidence).'"',
                '>Download evidence</a>',
            ], false)
            ->assertDontSee('A bounded local preview')
            ->assertDontSee('<pre', false);
    }

    private function submittedVersionFor(User $participant): SubmissionVersion
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 1]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
    }

    private function docxContents(string $bodyXml): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'qa-docx-');
        $archive = new ZipArchive;
        $archive->open($temporary);
        $archive->addFromString('word/document.xml', '<document><body>'.$bodyXml.'</body></document>');
        $archive->close();
        $contents = file_get_contents($temporary);
        unlink($temporary);

        return $contents;
    }
}
