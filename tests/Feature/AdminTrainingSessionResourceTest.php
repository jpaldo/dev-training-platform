<?php

namespace Tests\Feature;

use App\Enums\TrainingSessionResourceType;
use App\Models\AdminAuditLog;
use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTrainingSessionResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_uploads_a_pdf_to_week_one_that_is_stored_privately_and_audited(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'type' => 'file',
                'title' => 'Week 1 slides',
                'week_number' => '1',
                'file' => UploadedFile::fake()->create('week-1-slides.pdf', 2048, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session).'#resources')
            ->assertSessionHas('status', 'Added "Week 1 slides".');

        $resource = TrainingSessionResource::query()->sole();
        $this->assertSame($session->id, $resource->training_session_id);
        $this->assertSame(1, $resource->week_number);
        $this->assertSame('Week 1 slides', $resource->title);
        $this->assertSame(TrainingSessionResourceType::File, $resource->type);
        $this->assertNull($resource->url);
        $this->assertSame('local', $resource->disk);
        $this->assertStringStartsWith("training-sessions/{$session->id}/resources/", $resource->path);
        $this->assertSame('week-1-slides.pdf', $resource->original_filename);
        $this->assertSame('application/pdf', $resource->mime_type);
        $this->assertSame(2048 * 1024, $resource->size_bytes);
        $this->assertSame($admin->id, $resource->uploaded_by);
        Storage::disk('local')->assertExists($resource->path);

        $this->assertAudit($admin, 'session_resource_added', [
            'training_session_id' => $session->id,
            'training_session_resource_id' => $resource->id,
            'title' => 'Week 1 slides',
        ]);
    }

    public function test_admin_adds_a_youtube_link_to_the_whole_session(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'type' => 'link',
                'title' => 'Kick-off recording',
                'week_number' => '',
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'file' => UploadedFile::fake()->create('ignored.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sessions.show', $session).'#resources');

        $resource = TrainingSessionResource::query()->sole();
        $this->assertNull($resource->week_number);
        $this->assertSame(TrainingSessionResourceType::Link, $resource->type);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $resource->url);
        $this->assertNull($resource->disk);
        $this->assertNull($resource->path);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->assertAudit($admin, 'session_resource_added', [
            'training_session_id' => $session->id,
            'training_session_resource_id' => $resource->id,
            'title' => 'Kick-off recording',
        ]);
    }

    public function test_the_resources_tab_counts_and_groups_resources_by_whole_session_then_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        TrainingSessionResource::factory()->for($session)->file()->forWeek(2)->create(['title' => 'Week 2 workbook', 'original_filename' => 'workbook.pdf', 'size_bytes' => 512 * 1024]);
        TrainingSessionResource::factory()->for($session)->file()->forWeek(1)->create(['title' => 'Week 1 slides', 'original_filename' => 'slides.pdf', 'size_bytes' => 2 * 1024 * 1024]);
        $link = TrainingSessionResource::factory()->for($session)->create(['title' => 'Kick-off recording', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        TrainingSessionResource::factory()->create(['title' => 'Other session resource']);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('Resources (3)')
            ->assertDontSee('No resources in this session yet.')
            ->assertDontSee('Other session resource')
            ->assertSeeInOrder([
                'Whole session', 'Kick-off recording', 'Link', 'www.youtube.com',
                'Week 1', 'Week 1 slides', 'File', 'slides.pdf · 2 MB',
                'Week 2', 'Week 2 workbook', 'File', 'workbook.pdf · 512 KB',
            ])
            ->assertSee('href="'.route('training.sessions.resources.show', [$session, $link]).'"', false)
            ->assertSee('action="'.route('admin.sessions.resources.update', [$session, $link]).'"', false)
            ->assertSee('action="'.route('admin.sessions.resources.destroy', [$session, $link]).'" data-confirm="Remove Kick-off recording from this session?"', false);
    }

    public function test_the_add_forms_offer_the_whole_session_and_each_session_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create(['weeks_count' => 2]);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('No resources in this session yet.')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('maximum 100 MB')
            ->assertSeeInOrder(['>Whole session</option>', '<option value="1" >Week 1</option>', '<option value="2" >Week 2</option>'], false)
            ->assertDontSee('>Week 3</option>', false);
    }

    public function test_a_file_of_a_type_that_is_not_allowed_is_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'type' => 'file',
                'title' => 'Installer',
                'file' => UploadedFile::fake()->create('setup.exe', 100, 'application/x-msdownload'),
            ])
            ->assertRedirect(route('admin.sessions.show', $session).'#resources')
            ->assertSessionHasErrorsIn('resources', ['file' => 'The file must be a PDF, Word, Excel or PowerPoint document, an image or an MP4 video.']);

        $this->assertNothingAdded();
    }

    public function test_a_file_over_100_mb_is_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'type' => 'file',
                'title' => 'Huge recording',
                'file' => UploadedFile::fake()->create('recording.mp4', 101 * 1024, 'video/mp4'),
            ])
            ->assertSessionHasErrorsIn('resources', ['file' => 'The file must not exceed 100 MB.']);

        $this->assertNothingAdded();
    }

    /**
     * PHP rejects a body over `post_max_size` before routing (ValidatePostSize is global middleware), so the handler in
     * bootstrap/app.php returns the admin to the Resources tab. The session is not started at that point, so the flashed
     * size message does not survive a real request (see the plan's Open decisions); only the redirect is asserted.
     */
    public function test_an_upload_php_rejects_as_too_large_returns_to_the_resources_tab(): void
    {
        $postMaxSize = $this->iniBytes((string) ini_get('post_max_size'));
        if ($postMaxSize === 0) {
            $this->markTestSkipped('post_max_size is unlimited, so PHP never rejects the request body.');
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->call('POST', route('admin.sessions.resources.store', $session), server: ['CONTENT_LENGTH' => (string) ($postMaxSize + 1)])
            ->assertRedirect(route('admin.sessions.show', $session).'#resources');

        $this->assertDatabaseCount('training_session_resources', 0);
    }

    public function test_a_week_after_the_sessions_last_week_is_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create(['weeks_count' => 4]);

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [
                'type' => 'file',
                'title' => 'Week 5 slides',
                'week_number' => '5',
                'file' => UploadedFile::fake()->create('slides.pdf', 2048, 'application/pdf'),
            ])
            ->assertSessionHasErrorsIn('resources', ['week_number' => 'This session has only 4 weeks.']);

        $this->assertNothingAdded();
    }

    public function test_a_link_must_be_an_http_or_https_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        foreach (['javascript:alert(1)', 'ftp://files.example.com/slides.pdf'] as $url) {
            $this->actingAs($admin)
                ->post(route('admin.sessions.resources.store', $session), ['type' => 'link', 'title' => 'Bad link', 'url' => $url])
                ->assertSessionHasErrorsIn('resources', ['url' => 'The link must be a full web address starting with http:// or https://.']);
        }

        $this->assertDatabaseCount('training_session_resources', 0);
    }

    public function test_a_failed_add_shows_its_errors_and_input_in_its_own_form_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('admin.sessions.resources.store', $session), ['type' => 'link', 'title' => 'Shared drive', 'url' => 'ftp://files.example.com'])
            ->assertOk()
            ->assertSeeInOrder([
                'Upload a file', 'id="session-resource-file-name" name="title" type="text" required maxlength="255" value=""',
                'Add a link', 'The link must be a full web address starting with http:// or https://.',
                'id="session-resource-link-name" name="title" type="text" required maxlength="255" value="Shared drive"',
                'value="ftp://files.example.com"',
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
            ->assertSee('id="session-resource-'.$other->id.'-title" name="title" type="text" required maxlength="255" value="Recording"', false);
    }

    public function test_the_title_type_and_the_file_or_link_are_required(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), [])
            ->assertSessionHasErrorsIn('resources', ['title' => 'The title field is required.', 'type' => 'The type field is required.']);
        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), ['type' => 'file', 'title' => 'Slides'])
            ->assertSessionHasErrorsIn('resources', ['file' => 'The file field is required.']);
        $this->actingAs($admin)
            ->post(route('admin.sessions.resources.store', $session), ['type' => 'link', 'title' => 'Recording'])
            ->assertSessionHasErrorsIn('resources', ['url' => 'The link field is required.']);

        $this->assertDatabaseCount('training_session_resources', 0);
    }

    public function test_rename_changes_only_the_title_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resource = TrainingSessionResource::factory()->file()->forWeek(1)->create(['title' => 'Slides']);
        $before = $resource->only(['training_session_id', 'week_number', 'type', 'url', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by']);

        $this->actingAs($admin)
            ->put(route('admin.sessions.resources.update', [$resource->training_session_id, $resource]), ['title' => 'Week 1 slides', 'week_number' => '2'])
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

    public function test_remove_deletes_the_row_and_the_stored_file_and_is_audited(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $resource = TrainingSessionResource::factory()->file()->create(['title' => 'Slides']);
        Storage::disk('local')->put($resource->path, 'pdf');
        $kept = TrainingSessionResource::factory()->file()->for($resource->trainingSession)->create();
        Storage::disk('local')->put($kept->path, 'pdf');

        $this->actingAs($admin)
            ->delete(route('admin.sessions.resources.destroy', [$resource->training_session_id, $resource]))
            ->assertRedirect(route('admin.sessions.show', $resource->training_session_id).'#resources')
            ->assertSessionHas('status', 'Removed "Slides".');

        $this->assertModelMissing($resource);
        Storage::disk('local')->assertMissing($resource->path);
        $this->assertModelExists($kept);
        Storage::disk('local')->assertExists($kept->path);

        $this->assertAudit($admin, 'session_resource_removed', [
            'training_session_id' => $resource->training_session_id,
            'training_session_resource_id' => $resource->id,
            'title' => 'Slides',
        ]);
    }

    public function test_an_admin_opens_a_file_inline_and_a_link_redirects_to_its_address(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $file = TrainingSessionResource::factory()->file()->create(['original_filename' => 'slides.pdf']);
        Storage::disk('local')->put($file->path, '%PDF-1.7 slides');
        $link = TrainingSessionResource::factory()->create(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        $response = $this->actingAs($admin)
            ->get(route('training.sessions.resources.show', [$file->training_session_id, $file]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline; filename=slides.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.7 slides', $response->streamedContent());

        $this->actingAs($admin)
            ->get(route('training.sessions.resources.show', [$link->training_session_id, $link]))
            ->assertRedirect('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }

    public function test_a_file_missing_from_the_disk_is_not_found(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $file = TrainingSessionResource::factory()->file()->create();

        $this->actingAs($admin)
            ->get(route('training.sessions.resources.show', [$file->training_session_id, $file]))
            ->assertNotFound();
    }

    public function test_a_resource_of_another_session_is_not_found(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $session = TrainingSession::factory()->create();
        $resource = TrainingSessionResource::factory()->file()->create(['title' => 'Slides']);
        Storage::disk('local')->put($resource->path, 'pdf');

        $this->actingAs($admin)->get(route('training.sessions.resources.show', [$session, $resource]))->assertNotFound();
        $this->actingAs($admin)->put(route('admin.sessions.resources.update', [$session, $resource]), ['title' => 'Renamed'])->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.sessions.resources.destroy', [$session, $resource]))->assertNotFound();

        $this->assertSame('Slides', $resource->refresh()->title);
        Storage::disk('local')->assertExists($resource->path);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        Storage::fake('local');
        $resource = TrainingSessionResource::factory()->file()->create(['title' => 'Slides']);
        Storage::disk('local')->put($resource->path, 'pdf');
        $session = $resource->trainingSession;
        $payload = ['type' => 'link', 'title' => 'Recording', 'url' => 'https://example.com/recording'];

        $this->post(route('admin.sessions.resources.store', $session), $payload)->assertRedirect(route('login'));
        $this->put(route('admin.sessions.resources.update', [$session, $resource]), ['title' => 'Renamed'])->assertRedirect(route('login'));
        $this->delete(route('admin.sessions.resources.destroy', [$session, $resource]))->assertRedirect(route('login'));
        $this->get(route('training.sessions.resources.show', [$session, $resource]))->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $session->participants()->attach($user, ['joined_at' => now()]);

            $this->actingAs($user)->post(route('admin.sessions.resources.store', $session), $payload)->assertForbidden();
            $this->actingAs($user)->put(route('admin.sessions.resources.update', [$session, $resource]), ['title' => 'Renamed'])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.sessions.resources.destroy', [$session, $resource]))->assertForbidden();
            $this->actingAs($user)->get(route('training.sessions.resources.show', [$session, $resource]))->assertForbidden();
        }

        $this->assertSame(['Slides'], TrainingSessionResource::query()->pluck('title')->all());
        Storage::disk('local')->assertExists($resource->path);
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
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * Convert an ini size such as "8M" to bytes.
     */
    private function iniBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
