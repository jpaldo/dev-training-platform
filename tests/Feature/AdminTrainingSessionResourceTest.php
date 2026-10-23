<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminTrainingSessionResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SLIDES_URL = 'https://docs.google.com/presentation/d/abc123/edit?usp=sharing';

    private const YOUTUBE_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private const SCHEME_MESSAGE = 'The link must be a full web address starting with http:// or https://.';

    public function test_admin_adds_google_slides_to_week_one_with_a_lazy_preview_and_an_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'title' => 'Week 1 slides',
                'week_number' => '1',
                'url' => self::SLIDES_URL,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session).'#resources')
            ->assertSessionHas('status', 'Added "Week 1 slides".');

        $resource = TrainingSessionResource::query()->sole();
        $this->assertSame($session->id, $resource->training_session_id);
        $this->assertSame(1, $resource->week_number);
        $this->assertSame('Week 1 slides', $resource->title);
        $this->assertSame(self::SLIDES_URL, $resource->url);
        $this->assertSame($admin->id, $resource->added_by);
        $this->assertAudit($admin, 'session_resource_added', [
            'training_session_id' => $session->id,
            'training_session_resource_id' => $resource->id,
            'title' => 'Week 1 slides',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['Week 1', 'Week 1 slides', 'Google Slides', 'docs.google.com', '>Open in Drive<', 'Show preview'], false)
            ->assertSee('href="'.e(self::SLIDES_URL).'" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('data-preview-src="https://docs.google.com/presentation/d/abc123/preview"', false)
            ->assertSee('title="Preview of Week 1 slides"', false)
            ->assertSee('Preview not loading? Open it in Drive. If Drive says you need access, ask an admin to share the file.');
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-resource-preview[^>]*\sopen/', $response->getContent());
    }

    #[DataProvider('driveLinks')]
    public function test_drive_docs_and_sheets_links_get_their_preview_and_label(string $url, string $previewUrl, string $label): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), ['title' => 'Shared file', 'url' => $url])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['Whole session', 'Shared file', $label, '>Open in Drive<', 'Show preview'], false)
            ->assertSee('data-preview-src="'.$previewUrl.'"', false);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function driveLinks(): array
    {
        return [
            'drive file' => ['https://drive.google.com/file/d/1aB_c-D2/view?usp=sharing', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'drive open link' => ['https://drive.google.com/open?id=1aB_c-D2', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'google doc' => ['https://docs.google.com/document/d/abc123/edit', 'https://docs.google.com/document/d/abc123/preview', 'Google Docs'],
            'google sheet' => ['https://docs.google.com/spreadsheets/d/abc123/edit#gid=0', 'https://docs.google.com/spreadsheets/d/abc123/preview', 'Google Sheets'],
        ];
    }

    #[DataProvider('plainLinks')]
    public function test_other_links_get_an_open_link_and_no_preview(string $url, string $host): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), ['title' => 'Kick-off', 'week_number' => '', 'url' => $url])
            ->assertSessionHasNoErrors();

        $this->assertNull(TrainingSessionResource::query()->sole()->week_number);
        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['Whole session', 'Kick-off', '>Link<', $host, '>Open<'], false)
            ->assertSee('href="'.e($url).'" target="_blank" rel="noopener noreferrer"', false)
            ->assertDontSee('Show preview')
            ->assertDontSee('<iframe', false)
            ->assertDontSee('Open in Drive');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function plainLinks(): array
    {
        return [
            'youtube video' => [self::YOUTUBE_URL, 'www.youtube.com'],
            'drive folder' => ['https://drive.google.com/drive/folders/1aB_c-D2', 'drive.google.com'],
        ];
    }

    public function test_the_tab_counts_and_groups_resources_by_whole_session_then_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        TrainingSessionResource::factory()->for($session)->forWeek(2)->create(['title' => 'Week 2 workbook']);
        TrainingSessionResource::factory()->for($session)->googleDrive()->forWeek(1)->create(['title' => 'Week 1 slides']);
        $link = TrainingSessionResource::factory()->for($session)->create(['title' => 'Kick-off recording', 'url' => self::YOUTUBE_URL]);
        TrainingSessionResource::factory()->create(['title' => 'Other session resource']);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('Resources (3)')
            ->assertDontSee('No resources in this session yet.')
            ->assertDontSee('Other session resource')
            ->assertSeeInOrder(['Whole session', 'Kick-off recording', 'Week 1', 'Week 1 slides', 'Google Drive', 'Week 2', 'Week 2 workbook'])
            ->assertSee('action="'.route('admin.sessions.resources.update', [$session, $link]).'"', false)
            ->assertSee('action="'.route('admin.sessions.resources.destroy', [$session, $link]).'" data-confirm="Remove Kick-off recording from this session?"', false);
    }

    public function test_the_add_form_takes_a_link_shows_the_sharing_hint_and_offers_each_session_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create(['weeks_count' => 2]);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('No resources in this session yet.')
            ->assertSee('Add a resource')
            ->assertSee('Google Drive, Docs, Slides and Sheets links show a preview here. Other links, such as YouTube, open in a new tab.')
            ->assertSee('Share Google Drive files with ‘Anyone with the link’ or with everyone at Full Scale. The app cannot check sharing, and people without access cannot see the file.')
            ->assertSee('name="url" type="url" required', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('enctype=', false)
            ->assertSeeInOrder(['>Whole session</option>', '<option value="1" >Week 1</option>', '<option value="2" >Week 2</option>'], false)
            ->assertDontSee('>Week 3</option>', false);
    }

    #[DataProvider('linksWithoutAWebScheme')]
    public function test_a_link_must_be_an_http_or_https_address(string $url): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), ['title' => 'Bad link', 'url' => $url])
            ->assertRedirect(route('admin.sessions.show', $session).'#resources')
            ->assertSessionHasErrorsIn('resources', ['url' => self::SCHEME_MESSAGE]);

        $this->assertNothingAdded();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function linksWithoutAWebScheme(): array
    {
        return [
            'ftp' => ['ftp://example.com/x'],
            'javascript' => ['javascript:alert(1)'],
            'no scheme' => ['example.com'],
        ];
    }

    public function test_the_title_and_the_link_are_required(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [])
            ->assertSessionHasErrorsIn('resources', ['title' => 'The title field is required.', 'url' => 'The link field is required.']);

        $this->assertNothingAdded();
    }

    public function test_a_week_after_the_sessions_last_week_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create(['weeks_count' => 4]);

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), ['title' => 'Week 5 slides', 'week_number' => '5', 'url' => self::SLIDES_URL])
            ->assertSessionHasErrorsIn('resources', ['week_number' => 'This session has only 4 weeks.']);

        $this->assertNothingAdded();
    }

    public function test_a_file_without_a_link_is_rejected_and_nothing_is_stored(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'type' => 'file',
                'title' => 'Week 1 slides',
                'file' => UploadedFile::fake()->create('slides.pdf', 2048, 'application/pdf'),
            ])
            ->assertSessionHasErrorsIn('resources', ['url' => 'The link field is required.']);

        $this->assertNothingAdded();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_failed_add_shows_its_errors_and_keeps_its_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('admin.sessions.resources.store', $session), ['title' => 'Shared drive', 'week_number' => '2', 'url' => 'ftp://files.example.com'])
            ->assertOk()
            ->assertSeeInOrder([
                'Add a resource', self::SCHEME_MESSAGE,
                'id="session-resource-add-name" name="title" type="text" required maxlength="255" value="Shared drive"',
                'value="ftp://files.example.com"',
                '<option value="2" selected>Week 2</option>',
            ], false);
    }

    public function test_a_failed_rename_shows_its_error_beside_that_resource_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $renamed = TrainingSessionResource::factory()->for($session)->create(['title' => 'Slides']);
        $other = TrainingSessionResource::factory()->for($session)->create(['title' => 'Recording']);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.sessions.resources.update', [$session, $renamed]), ['title' => ''])
            ->assertOk()
            ->assertSee('id="session-resource-'.$renamed->id.'-title-error" class="text-sm text-rose-200" role="alert">The title field is required.</p>', false)
            ->assertDontSee('id="session-resource-'.$other->id.'-title-error"', false)
            ->assertSee('id="session-resource-'.$other->id.'-title" name="title" type="text" required maxlength="255" value="Recording"', false)
            ->assertSee('id="session-resource-add-name" name="title" type="text" required maxlength="255" value=""', false);
    }

    public function test_rename_changes_only_the_title_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resource = TrainingSessionResource::factory()->googleDrive()->forWeek(1)->create(['title' => 'Slides', 'added_by' => $admin->id]);
        $before = $resource->only(['training_session_id', 'week_number', 'url', 'added_by']);

        $this->actingAs($admin)
            ->put(route('admin.sessions.resources.update', [$resource->training_session_id, $resource]), ['title' => 'Week 1 slides', 'week_number' => '2', 'url' => self::YOUTUBE_URL])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $resource->training_session_id).'#resources')
            ->assertSessionHas('status', 'Renamed the resource to "Week 1 slides".');

        $resource->refresh();
        $this->assertSame('Week 1 slides', $resource->title);
        $this->assertSame($before, $resource->only(array_keys($before)));

        $this->assertAudit($admin, 'session_resource_renamed', [
            'training_session_id' => $resource->training_session_id,
            'training_session_resource_id' => $resource->id,
            'title' => 'Week 1 slides',
            'previous_title' => 'Slides',
        ]);
    }

    public function test_a_rename_without_a_title_fails_in_the_resources_own_error_bag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resource = TrainingSessionResource::factory()->create(['title' => 'Slides']);

        $this->actingAs($admin)
            ->put(route('admin.sessions.resources.update', [$resource->training_session_id, $resource]), ['title' => ''])
            ->assertRedirect(route('admin.sessions.show', $resource->training_session_id).'#resources')
            ->assertSessionHasErrorsIn("renameResource{$resource->id}", ['title' => 'The title field is required.']);

        $this->assertSame('Slides', $resource->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_remove_deletes_only_that_row_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resource = TrainingSessionResource::factory()->googleDrive()->create(['title' => 'Slides']);
        $kept = TrainingSessionResource::factory()->for($resource->trainingSession)->create();

        $this->actingAs($admin)
            ->delete(route('admin.sessions.resources.destroy', [$resource->training_session_id, $resource]))
            ->assertRedirect(route('admin.sessions.show', $resource->training_session_id).'#resources')
            ->assertSessionHas('status', 'Removed "Slides".');

        $this->assertModelMissing($resource);
        $this->assertModelExists($kept);
        $this->assertAudit($admin, 'session_resource_removed', [
            'training_session_id' => $resource->training_session_id,
            'training_session_resource_id' => $resource->id,
            'title' => 'Slides',
        ]);
    }

    public function test_a_resource_of_another_session_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $resource = TrainingSessionResource::factory()->create(['title' => 'Slides']);

        $this->actingAs($admin)->put(route('admin.sessions.resources.update', [$session, $resource]), ['title' => 'Renamed'])->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.sessions.resources.destroy', [$session, $resource]))->assertNotFound();

        $this->assertSame('Slides', $resource->refresh()->title);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_the_old_resource_open_route_is_gone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resource = TrainingSessionResource::factory()->create();

        $this->actingAs($admin)
            ->get("/training/sessions/{$resource->training_session_id}/resources/{$resource->id}")
            ->assertNotFound();
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $resource = TrainingSessionResource::factory()->create(['title' => 'Slides']);
        $session = $resource->trainingSession;
        $payload = ['title' => 'Recording', 'url' => 'https://example.com/recording'];

        $this->post(route('admin.sessions.resources.store', $session), $payload)->assertRedirect(route('login'));
        $this->put(route('admin.sessions.resources.update', [$session, $resource]), ['title' => 'Renamed'])->assertRedirect(route('login'));
        $this->delete(route('admin.sessions.resources.destroy', [$session, $resource]))->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $session->participants()->attach($user, ['joined_at' => now()]);

            $this->actingAs($user)->post(route('admin.sessions.resources.store', $session), $payload)->assertForbidden();
            $this->actingAs($user)->put(route('admin.sessions.resources.update', [$session, $resource]), ['title' => 'Renamed'])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.sessions.resources.destroy', [$session, $resource]))->assertForbidden();
        }

        $this->assertSame(['Slides'], TrainingSessionResource::query()->pluck('title')->all());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * Assert that the only audit row is the admin's action with the metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function assertAudit(User $admin, string $action, array $metadata): void
    {
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame($action, $audit->action);
        $this->assertSame($metadata, $audit->metadata);
    }

    private function assertNothingAdded(): void
    {
        $this->assertDatabaseCount('training_session_resources', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
