<?php

namespace Tests\Feature;

use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklyVideoRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_upload_a_private_week_video_recording(): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSee('Video Recording')
            ->assertSee('Upload Video Recording')
            ->assertSee('maximum 350 MB')
            ->assertSee('accept="video/*"', false)
            ->assertSee('data-weekly-video-form', false)
            ->assertSee('data-max-bytes="367001600"', false)
            ->assertSee('The video must not exceed 350 MB.', false);
        $this->actingAs($participant)->post(route('training.video-recording.store', $week), ['video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4')])->assertRedirect(route('training.video-recording.edit', $week));
        $recording = WeeklyVideoRecording::firstOrFail();
        $this->assertSame($participant->id, $recording->user_id);
        Storage::disk('local')->assertExists($recording->path);
        $this->actingAs($participant)->get(route('training.video-recording.download', [$week, $recording]))->assertOk();
    }

    public function test_participant_video_actions_are_available_on_week_and_dashboard_pages(): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4'),
        ])->assertRedirect();
        $recording = WeeklyVideoRecording::firstOrFail();
        $previewUrl = route('training.video-recording.view', [$week, $recording]);
        $downloadUrl = route('training.video-recording.download', [$week, $recording]);

        foreach ([route('training.weeks.show', $week), route('dashboard')] as $pageUrl) {
            $this->actingAs($participant)->get($pageUrl)
                ->assertOk()
                ->assertSee('Preview Video')
                ->assertSee('Download Video')
                ->assertSee('Replace Video')
                ->assertSee($previewUrl, false)
                ->assertSee($downloadUrl, false)
                ->assertSee('<dialog', false)
                ->assertSee('<video', false);
        }
    }

    public function test_recording_page_opens_inline_video_preview_in_a_modal(): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4'),
        ])->assertRedirect();
        $recording = WeeklyVideoRecording::firstOrFail();
        $previewUrl = route('training.video-recording.view', [$week, $recording]);
        $downloadUrl = route('training.video-recording.download', [$week, $recording]);

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSee('View Video')
            ->assertSee($previewUrl, false)
            ->assertSee('<dialog', false)
            ->assertSee('<video', false)
            ->assertDontSee($downloadUrl, false)
            ->assertDontSee('Download Video');
    }

    public function test_video_preview_is_inline_and_private(): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4'),
        ])->assertRedirect();
        $recording = WeeklyVideoRecording::firstOrFail();
        $previewUrl = route('training.video-recording.view', [$week, $recording]);
        $downloadUrl = route('training.video-recording.download', [$week, $recording]);

        $previewResponse = $this->actingAs($participant)->get($previewUrl);
        $previewResponse->assertOk()->assertHeader('content-type', 'video/mp4');
        $this->assertStringStartsWith('inline;', (string) $previewResponse->headers->get('content-disposition'));

        $downloadResponse = $this->actingAs($participant)->get($downloadUrl);
        $downloadResponse->assertOk()->assertHeader('content-type', 'video/mp4');
        $this->assertStringStartsWith('attachment;', (string) $downloadResponse->headers->get('content-disposition'));

        $otherParticipant = User::factory()->create();
        $unassignedCoach = User::factory()->create(['role' => 'coach']);
        $this->actingAs($otherParticipant)->get($previewUrl)->assertForbidden();
        $this->actingAs($unassignedCoach)->get($previewUrl)->assertForbidden();

        $assignedCoach = User::factory()->create(['role' => 'coach']);
        $assignedCoach->assignedParticipants()->attach($participant->id);
        $this->actingAs($assignedCoach)->get($previewUrl)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($previewUrl)->assertOk();

        $otherWeek = TrainingWeek::factory()->for($week->program)->create([
            'week_number' => 3,
            'position' => 2,
            'is_active' => true,
        ]);
        $this->actingAs($participant)->get(route('training.video-recording.view', [$otherWeek, $recording]))->assertNotFound();

        Storage::disk('local')->delete($recording->path);
        $this->actingAs($participant)->get($previewUrl)->assertNotFound();
    }

    public function test_other_participant_cannot_download_a_recording(): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();
        $this->actingAs($participant)->post(route('training.video-recording.store', $week), ['video' => UploadedFile::fake()->create('week-2.webm', 100, 'video/webm')]);
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('training.video-recording.download', [$week, WeeklyVideoRecording::firstOrFail()]))->assertForbidden();
    }

    #[DataProvider('videoFileProvider')]
    public function test_supported_video_formats_are_accepted(string $extension, string $mime): void
    {
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('recording.'.$extension, 100, $mime),
        ])->assertRedirect();

        $this->assertDatabaseHas('weekly_video_recordings', ['original_filename' => 'recording.'.$extension]);
    }

    public static function videoFileProvider(): array
    {
        return [
            ['mp4', 'video/mp4'], ['mov', 'video/quicktime'], ['webm', 'video/webm'], ['m4v', 'video/x-m4v'],
            ['avi', 'video/x-msvideo'], ['mkv', 'video/x-matroska'], ['mpg', 'video/mpeg'], ['mpeg', 'video/mpeg'],
            ['3gp', 'video/3gpp'], ['3g2', 'video/3gpp2'], ['ogv', 'video/ogg'], ['ogg', 'video/ogg'],
            ['mts', 'video/mp2t'], ['m2ts', 'video/mp2t'], ['ts', 'video/mp2t'], ['vob', 'video/mpeg'],
            ['wmv', 'video/x-ms-wmv'], ['flv', 'video/x-flv'], ['f4v', 'video/x-f4v'],
        ];
    }

    #[DataProvider('videoSizeProvider')]
    public function test_weekly_video_size_boundary_is_350_mb(int $sizeKb, bool $accepted): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();

        $response = $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('boundary.mp4', $sizeKb, 'video/mp4'),
        ]);

        if ($accepted) {
            $response->assertRedirect();
            $this->assertDatabaseHas('weekly_video_recordings', ['original_filename' => 'boundary.mp4']);
        } else {
            $response->assertSessionHasErrors('video');
            $this->assertDatabaseCount('weekly_video_recordings', 0);
        }
    }

    public static function videoSizeProvider(): array
    {
        return [[1, true], [358399, true], [358400, true], [358401, false]];
    }

    public function test_weekly_video_limit_is_independent_from_general_evidence_limit(): void
    {
        $this->assertSame(358400, config('training.weekly_video_max_kb'));
        $this->assertSame(307200, config('training.evidence_max_kb'));
    }

    public function test_non_video_formats_are_rejected_for_weekly_recording(): void
    {
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ])->assertSessionHasErrors('video');
    }

    public function test_image_content_renamed_as_mp4_is_rejected(): void
    {
        [$participant, $week] = $this->week();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('renamed.mp4', 100, 'image/png'),
        ])->assertSessionHasErrors('video');
        $this->assertDatabaseCount('weekly_video_recordings', 0);
    }

    public function test_real_webm_container_bytes_are_accepted(): void
    {
        [$participant, $week] = $this->week();

        $webm = UploadedFile::fake()->createWithContent(
            'recording.webm',
            hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810242858102')
        );

        $this->assertSame('video/webm', $webm->getMimeType());

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => $webm,
        ])->assertRedirect();

        $this->assertDatabaseHas('weekly_video_recordings', ['original_filename' => 'recording.webm']);
    }

    public function test_genuine_webm_with_generic_server_mime_uses_container_fallback(): void
    {
        [$participant, $week] = $this->week();
        $path = tempnam(sys_get_temp_dir(), 'qa-webm-');
        file_put_contents($path, hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810242858102'));

        $webm = new class($path, 'recording.webm', 'application/octet-stream', null, true) extends UploadedFile
        {
            public function getMimeType(): ?string
            {
                return 'application/octet-stream';
            }
        };

        try {
            $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
                'video' => $webm,
            ])->assertRedirect();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('weekly_video_recordings', ['original_filename' => 'recording.webm']);
    }

    public function test_non_video_content_with_video_extensions_is_rejected(): void
    {
        [$participant, $week] = $this->week();

        foreach (['renamed.png.mp4' => 'image/png', 'renamed.png.webm' => 'image/png', 'notes.webm' => 'text/plain', 'document.mp4' => 'application/pdf'] as $filename => $mime) {
            $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
                'video' => UploadedFile::fake()->create($filename, 20, $mime),
            ])->assertSessionHasErrors('video');
        }

        $this->assertDatabaseCount('weekly_video_recordings', 0);
    }

    public function test_upload_to_a_week_locked_after_gate_completion_is_rejected_before_validation(): void
    {
        [$participant, $week] = $this->week();
        GateEvaluation::factory()->for($week->gates()->firstOrFail(), 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [])->assertStatus(422);
        $this->assertDatabaseCount('weekly_video_recordings', 0);
    }

    public function test_upload_to_an_inaccessible_week_is_forbidden_before_validation(): void
    {
        [$participant, $week] = $this->week();
        $nextWeek = TrainingWeek::factory()->for($week->program)->create(['week_number' => 3, 'position' => 2, 'is_active' => true]);
        $this->addVideoGate($nextWeek);

        $this->actingAs($participant)->post(route('training.video-recording.store', $nextWeek), [])->assertForbidden();
    }

    public function test_upload_to_a_week_whose_checklist_has_no_video_item_is_not_found_before_validation(): void
    {
        Storage::fake('local');
        [$participant, $week] = $this->week();
        $week->gates()->firstOrFail()->criteria()->update(['criterion_type' => 'deliverable']);

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [
            'video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4'),
        ])->assertNotFound();
        $this->assertDatabaseCount('weekly_video_recordings', 0);
    }

    /**
     * A participant and the active first week (Week 2) of an active program, whose active Gate has a weekly video item.
     *
     * @return array{User, TrainingWeek}
     */
    private function week(): array
    {
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1, 'is_active' => true]);
        $this->addVideoGate($week);

        return [User::factory()->create(['role' => 'participant']), $week];
    }

    private function addVideoGate(TrainingWeek $week): void
    {
        $gate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => true]);
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
    }
}
