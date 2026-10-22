<?php

namespace Tests\Feature;

use App\Models\SubmissionEvidence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvidenceActionsComponentTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, array{string, ?string, string, list<string>}>
     */
    public static function previewableFiles(): array
    {
        return [
            'image by extension' => ['proof.PNG', null, 'image', ['PNG', 'data-evidence-preview-image', 'data-preview-src="{view}"']],
            'image by mime type' => ['screenshot', 'image/webp', 'image', ['IMAGE/WEBP', 'data-evidence-preview-image', 'data-preview-src="{view}"']],
            'pdf' => ['report.pdf', 'application/pdf', 'pdf', ['PDF', 'data-evidence-preview-frame', 'data-preview-src="{view}"']],
            'video by mime type' => ['walkthrough', 'video/quicktime', 'video', ['VIDEO/QUICKTIME', 'data-evidence-preview-video', 'data-preview-src="{view}"', '<source type="video/quicktime">']],
            'video by extension' => ['walkthrough.m4v', null, 'video', ['M4V', 'data-evidence-preview-video', 'data-preview-src="{view}"', '<source type="video/mp4">']],
            'structured' => ['results.csv', 'text/csv', 'structured', ['CSV', 'data-evidence-preview-text', 'data-preview-src="{preview}"', 'data-evidence-preview-table']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('previewableFiles')]
    public function test_previewable_files_open_the_preview_modal_with_the_matching_viewer(string $filename, ?string $mimeType, string $kind, array $expected): void
    {
        $evidence = $this->fileEvidence($filename, $mimeType);
        $urls = [
            '{view}' => route('training.evidence.view', $evidence),
            '{preview}' => route('training.evidence.preview', $evidence),
        ];
        $modalId = 'evidence-preview-'.$evidence->id;

        $view = $this->blade('<x-evidence-actions :evidence="$evidence" />', ['evidence' => $evidence]);

        $view->assertSeeInOrder([
            '<button type="button"',
            'data-evidence-preview-open="data-evidence-preview-open" data-evidence-preview-dialog="'.$modalId.'"',
            '>View</button>',
            '<dialog',
            'id="'.$modalId.'"',
            'aria-labelledby="'.$modalId.'-title"',
            'data-evidence-preview-kind="'.$kind.'"',
            'id="'.$modalId.'-title"', '>'.$filename.'</h2>',
            ...array_map(fn (string $text): string => strtr($text, $urls), $expected),
            'data-evidence-preview-close="data-evidence-preview-close"', '>Close</button>',
            'href="'.route('training.evidence.download', $evidence).'"', '>Download</a>',
            '</dialog>',
        ], false);
        $view->assertDontSee('from-brand to-brand-deep', false)
            ->assertDontSee('data-evidence-preview-fallback', false)
            ->assertDontSee('PDF preview is not available in this browser.');
    }

    public function test_unsupported_files_link_to_view_and_download_without_a_modal(): void
    {
        $evidence = $this->fileEvidence('checklists.zip', 'application/zip');

        $this->blade('<x-evidence-actions :evidence="$evidence" />', ['evidence' => $evidence])
            ->assertSeeInOrder([
                'href="'.route('training.evidence.view', $evidence).'"', '>View</a>',
                'href="'.route('training.evidence.download', $evidence).'"', '>Download</a>',
            ], false)
            ->assertDontSee('<dialog', false)
            ->assertDontSee('data-evidence-preview-open', false);
    }

    public function test_the_modal_heading_falls_back_to_the_title_and_then_to_evidence(): void
    {
        $titled = SubmissionEvidence::factory()->create([
            'evidence_type' => 'file',
            'title' => 'Login screenshot',
            'original_filename' => null,
            'mime_type' => 'image/png',
        ]);
        $untitled = SubmissionEvidence::factory()->create([
            'evidence_type' => 'file',
            'title' => null,
            'original_filename' => null,
            'mime_type' => 'application/pdf',
        ]);

        $this->blade('<x-evidence-actions :evidence="$evidence" />', ['evidence' => $titled])
            ->assertSeeInOrder(['>Login screenshot</h2>', '>IMAGE/PNG</p>', 'alt="Login screenshot"'], false);
        $this->blade('<x-evidence-actions :evidence="$evidence" />', ['evidence' => $untitled])
            ->assertSeeInOrder(['>Evidence</h2>', '>APPLICATION/PDF</p>', 'title="Evidence"'], false);
    }

    public function test_passed_urls_and_modal_id_replace_the_evidence_routes(): void
    {
        $evidence = $this->fileEvidence('prompt.md', 'text/markdown');

        $this->blade(
            '<x-evidence-actions :evidence="$evidence" view-url="/assets/1/view" download-url="/assets/1/download" preview-url="/assets/1/preview" modal-id="asset-preview-1" />',
            ['evidence' => $evidence],
        )
            ->assertSeeInOrder([
                'data-evidence-preview-dialog="asset-preview-1"',
                'id="asset-preview-1"',
                'data-preview-src="/assets/1/preview"',
                'href="/assets/1/download"',
            ], false)
            ->assertDontSee(route('training.evidence.preview', $evidence), false)
            ->assertDontSee(route('training.evidence.download', $evidence), false);
    }

    private function fileEvidence(string $filename, ?string $mimeType): SubmissionEvidence
    {
        return SubmissionEvidence::factory()->create([
            'evidence_type' => 'file',
            'content' => null,
            'disk' => 'local',
            'path' => 'evidence/'.$filename,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
        ]);
    }
}
