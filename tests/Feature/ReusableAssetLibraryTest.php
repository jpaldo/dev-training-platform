<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReusableAssetLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_activity_one_assets_are_listed_with_owner_name(): void
    {
        Storage::fake('local');
        [$owner, $approvedVersion] = $this->assetFixture('approved');
        $owner->update(['email' => 'owner-private@example.test']);
        [, $draftVersion] = $this->assetFixture('submitted');
        $viewer = User::factory()->create(['name' => 'Library Viewer']);

        $this->actingAs($viewer)
            ->get(route('training.reusable-assets.index'))
            ->assertOk()
            ->assertSee('Reusable AI Asset Library')
            ->assertSee($owner->name)
            ->assertDontSee($owner->email)
            ->assertSee('Requirement Test Generator')
            ->assertSee('Prompt Template')
            ->assertSee(route('training.reusable-assets.show', $approvedVersion), false)
            ->assertDontSee($draftVersion->field_values['asset_name']);

        $this->actingAs($viewer)
            ->get(route('training.reusable-assets.index', ['search' => 'Asset Owner', 'asset_type' => 'Prompt Template']))
            ->assertOk()
            ->assertSee('Requirement Test Generator');

        $this->actingAs($viewer)
            ->get(route('training.reusable-assets.index', ['search' => 'does-not-exist']))
            ->assertOk()
            ->assertSee('No reusable assets have been published yet.');
    }

    public function test_authenticated_users_can_view_details_preview_and_download_published_asset(): void
    {
        Storage::fake('local');
        [$owner, $version] = $this->assetFixture('approved');
        $viewer = User::factory()->create(['name' => 'Library Viewer']);

        $this->actingAs($viewer)
            ->get(route('training.reusable-assets.show', $version))
            ->assertOk()
            ->assertSee($owner->name)
            ->assertSee('Original Task')
            ->assertSee('Second Real Task')
            ->assertDontSee('submission_version_id');

        $this->actingAs($viewer)
            ->get(route('training.reusable-assets.preview', $version))
            ->assertOk()
            ->assertJsonPath('kind', 'text')
            ->assertJsonPath('content', 'Reusable prompt content');

        $this->actingAs($viewer)
            ->get(route('training.reusable-assets.download', $version))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=asset.md');
    }

    public function test_draft_assets_and_arbitrary_versions_are_not_exposed(): void
    {
        Storage::fake('local');
        [, $draftVersion] = $this->assetFixture('submitted');
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get(route('training.reusable-assets.show', $draftVersion))->assertNotFound();
        $this->actingAs($viewer)->get('/training/reusable-assets/999999')->assertNotFound();
        Auth::logout();
        $this->get(route('training.reusable-assets.index'))->assertRedirect(route('login'));
    }

    /** @return array{0: User, 1: SubmissionVersion} */
    private function assetFixture(string $submissionStatus): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $activity = $program->weeks()->where('week_number', 4)->firstOrFail()->activities()->where('position', 1)->firstOrFail();
        $owner = User::factory()->create(['name' => $submissionStatus === 'approved' ? 'Asset Owner' : 'Draft Owner']);
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $owner->id, 'status' => $submissionStatus, 'completed_at' => $submissionStatus === 'approved' ? now() : null]);
        $version = $submission->versions()->create([
            'version_number' => 1,
            'status' => $submissionStatus === 'approved' ? 'submitted' : 'draft',
            'field_values' => [
                'asset_name' => $submissionStatus === 'approved' ? 'Requirement Test Generator' : 'Draft Asset',
                'asset_type' => 'Prompt Template',
                'asset_purpose' => 'Generate structured test cases from requirements.',
                'original_task' => 'Original Task',
                'second_task' => 'Second Real Task',
            ],
        ]);
        $requirement = $activity->requirements()->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->firstWhere('evidence_type', 'asset');
        $path = 'evidence/'.$owner->id.'/'.$version->id.'/asset.md';
        Storage::disk('local')->put($path, 'Reusable prompt content');
        SubmissionEvidence::create([
            'submission_version_id' => $version->id,
            'evidence_requirement_id' => $requirement->id,
            'evidence_type' => 'file',
            'title' => 'Reusable AI Asset',
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'asset.md',
            'mime_type' => 'text/markdown',
            'size_bytes' => 24,
        ]);

        return [$owner, $version];
    }
}
