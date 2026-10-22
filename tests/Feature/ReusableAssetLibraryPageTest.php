<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Database\Seeders\QaTrainingProgramSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReusableAssetLibraryPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?Activity $assetActivity = null;

    public function test_library_cards_show_the_asset_details_and_file_actions_without_a_main_action(): void
    {
        $previewable = $this->publishedAsset('Ada Owner', 'prompt.md', [
            'asset_name' => 'Requirement Test Generator',
            'asset_type' => 'Prompt Template',
            'asset_purpose' => 'Generate structured test cases.',
        ], now()->subDay());
        $archive = $this->publishedAsset('Ben Owner', 'checklists.zip', [], now()->subDays(2));

        $content = $this->actingAs(User::factory()->create())->get(route('training.reusable-assets.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'TRAINING COMMUNITY',
                'Reusable AI Asset Library',
                'Explore reusable AI assets created and validated by participants during Week 4.',
                'aria-label="Filter reusable assets"',
                'Prompt Template', 'Requirement Test Generator', '<strong>Owner:</strong> Ada Owner', 'Generate structured test cases.', '<strong>File:</strong> prompt.md',
                'href="'.route('training.reusable-assets.show', $previewable).'"', 'View Details',
                'data-evidence-preview-dialog="reusable-asset-preview-'.$previewable->id.'"',
                'Reusable QA Asset', 'Reusable AI Asset', '<strong>Owner:</strong> Ben Owner', 'Validated reusable QA asset.', '<strong>File:</strong> checklists.zip',
                'href="'.route('training.reusable-assets.show', $archive).'"', 'View Details',
                'Preview unavailable for this file type.',
                'href="'.route('training.reusable-assets.download', $archive).'"', 'Download',
            ], false)
            ->assertDontSee('reusable-asset-preview-'.$archive->id, false)
            ->getContent();

        $this->assertSame(2, substr_count($content, 'data-reusable-asset'));
        $this->assertStringNotContainsString('from-brand to-brand-deep', $content);
    }

    public function test_filters_keep_their_values_and_an_empty_result_shows_a_notice(): void
    {
        $this->publishedAsset('Ada Owner', 'prompt.md', ['asset_name' => 'Requirement Test Generator', 'asset_type' => 'Prompt Template']);

        $this->actingAs(User::factory()->create())
            ->get(route('training.reusable-assets.index', ['search' => 'Nothing matches', 'asset_type' => 'Checklist']))
            ->assertOk()
            ->assertSee('name="search" value="Nothing matches"', false)
            ->assertSeeInOrder([
                '<option value="">All types</option>',
                '<option value="Checklist" selected>Checklist</option>',
                '<option value="Other Reusable QA Asset" >Other Reusable QA Asset</option>',
                '<option value="Prompt Template" >Prompt Template</option>',
                '<option value="Saved Agent / Configuration" >Saved Agent / Configuration</option>',
            ], false)
            ->assertSee('No reusable assets have been published yet.')
            ->assertSee('Approved Week 4 reusable assets will appear here.')
            ->assertDontSee('data-reusable-asset', false)
            ->assertDontSee('Requirement Test Generator');
    }

    public function test_an_invalid_filter_returns_to_the_library_with_the_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('training.reusable-assets.index'))
            ->followingRedirects()
            ->get(route('training.reusable-assets.index', ['search' => str_repeat('a', 101)]))
            ->assertOk()
            ->assertSeeInOrder(['>Reusable AI Asset Library</h1>', 'role="alert"', 'The search field must not be greater than 100 characters.', 'aria-label="Filter reusable assets"'], false);
    }

    public function test_library_lists_twelve_assets_per_page_and_keeps_the_filters_in_the_page_links(): void
    {
        foreach (range(1, 13) as $day) {
            $this->publishedAsset("Owner {$day}", 'prompt.md', ['asset_type' => 'Prompt Template'], now()->subDays($day));
        }

        $content = $this->actingAs(User::factory()->create())
            ->get(route('training.reusable-assets.index', ['asset_type' => 'Prompt Template']))
            ->assertOk()
            ->assertSee('Owner 12')
            ->assertDontSee('Owner 13')
            ->assertSee('asset_type=Prompt%20Template&amp;page=2', false)
            ->getContent();

        $this->assertSame(12, substr_count($content, 'data-reusable-asset'));
    }

    public function test_library_query_count_does_not_grow_with_the_number_of_assets(): void
    {
        $this->assertSame($this->libraryQueryCount(1), $this->libraryQueryCount(3));
    }

    public function test_detail_page_shows_the_summary_the_filled_details_in_order_and_the_asset_actions(): void
    {
        $version = $this->publishedAsset('Ada Owner', 'prompt.md', [
            'asset_name' => 'Requirement Test Generator',
            'asset_type' => 'Prompt Template',
            'asset_purpose' => 'Generate structured test cases.',
            'reuse_result' => 'Saved two hours.',
            'original_task' => 'Checkout requirements',
            'reuse_changes' => ['scope' => 'api/v2'],
            'original_use' => '',
        ]);

        $content = $this->actingAs(User::factory()->create())->get(route('training.reusable-assets.show', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'REUSABLE AI ASSET',
                'Requirement Test Generator',
                'Owner</dt>', 'Ada Owner',
                'Asset Type</dt>', 'Prompt Template',
                'Purpose</dt>', 'Generate structured test cases.',
                'About this asset',
                'Original Task', 'Checkout requirements',
                'Reuse Result', 'Saved two hours.',
                'Changes Made', '&quot;scope&quot;: &quot;api/v2&quot;',
                '<strong>prompt.md</strong>',
                'data-evidence-preview-dialog="reusable-asset-detail-preview-'.$version->id.'"',
                'href="'.route('training.reusable-assets.index').'"', 'Back to Library',
            ], false)
            ->assertDontSee('How It Was Used')
            ->assertDontSee('No asset details were provided.')
            ->getContent();

        $this->assertSame(3, substr_count($content, 'data-asset-detail'));
        $this->assertStringNotContainsString('from-brand to-brand-deep', $content);
    }

    public function test_detail_page_without_details_shows_a_notice_and_a_download_for_archives(): void
    {
        $version = $this->publishedAsset('Ada Owner', 'checklists.zip', []);

        $this->actingAs(User::factory()->create())->get(route('training.reusable-assets.show', $version))
            ->assertOk()
            ->assertSeeInOrder([
                'Reusable AI Asset',
                'Asset Type</dt>', 'Reusable QA Asset',
                'Purpose</dt>', 'Not provided',
                'No asset details were provided.',
                '<strong>checklists.zip</strong>',
                'Preview unavailable for this archive file type.',
                'href="'.route('training.reusable-assets.download', $version).'"', 'Download',
            ], false)
            ->assertDontSee('data-asset-detail', false)
            ->assertDontSee('data-evidence-preview-dialog', false);
    }

    /**
     * Publish the given number of assets and count the queries of the library request after a warm-up request.
     */
    private function libraryQueryCount(int $assetCount): int
    {
        foreach (range(1, $assetCount) as $index) {
            $this->publishedAsset("Counted Owner {$index}", 'prompt.md', ['asset_name' => "Counted Asset {$index}"]);
        }
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get(route('training.reusable-assets.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get(route('training.reusable-assets.index'))->assertOk()->assertSee('Counted Asset 1');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Create an approved Week 4 Activity 1 submission for a new owner whose submitted version holds an asset file.
     *
     * @param  array<string, mixed>  $fieldValues
     */
    private function publishedAsset(string $ownerName, string $filename, array $fieldValues, ?DateTimeInterface $completedAt = null): SubmissionVersion
    {
        $activity = $this->assetActivity();
        $owner = User::factory()->create(['name' => $ownerName]);
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $owner->id, 'status' => 'approved', 'completed_at' => $completedAt ?? now()]);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => $fieldValues]);
        $requirement = $activity->requirements->flatMap->evidenceRequirements->firstWhere('evidence_type', 'asset');
        SubmissionEvidence::create([
            'submission_version_id' => $version->id,
            'evidence_requirement_id' => $requirement->id,
            'evidence_type' => 'file',
            'title' => 'Reusable AI Asset',
            'disk' => 'local',
            'path' => "evidence/{$owner->id}/{$version->id}/{$filename}",
            'original_filename' => $filename,
            'mime_type' => 'text/markdown',
            'size_bytes' => 24,
        ]);

        return $version;
    }

    private function assetActivity(): Activity
    {
        if ($this->assetActivity === null) {
            $this->seed(QaTrainingProgramSeeder::class);
            $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
            $this->assetActivity = $program->weeks()->where('week_number', 4)->firstOrFail()
                ->activities()->where('position', 1)->with('requirements.evidenceRequirements')->firstOrFail();
        }

        return $this->assetActivity;
    }
}
