<?php

namespace Tests\Feature;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Services\GoogleDriveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleDriveVideoFileTest extends TestCase
{
    use RefreshDatabase;

    private const FILE_URL = 'https://www.googleapis.com/drive/v3/files/video-file-1?fields=id%2Cname%2CmimeType%2Csize%2CcreatedTime%2CmodifiedTime%2Ctrashed%2CownedByMe%2CresourceKey&supportsAllDrives=true';

    private const UNAVAILABLE_MESSAGE = 'The app could not open that file in your Google Drive. Choose it again, or reconnect Google Drive on your profile.';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_drive.enabled', true);
        config()->set('services.google_drive.client_id', 'client-id');
        config()->set('services.google_drive.client_secret', 'client-secret');
        config()->set('services.google_drive.redirect_uri', 'http://localhost/profile/google-drive/callback');
    }

    public function test_owned_untrashed_video_returns_its_details(): void
    {
        $connection = $this->connection();
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/files/video-file-1*' => Http::response(self::driveVideo())]);

        $file = app(GoogleDriveService::class)->videoFile($connection, 'video-file-1');

        $this->assertSame([
            'id' => 'video-file-1',
            'name' => 'Week 1 demo.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 73400320,
            'resource_key' => null,
        ], Arr::except($file, ['created_at', 'modified_at']));
        $this->assertInstanceOf(CarbonImmutable::class, $file['created_at']);
        $this->assertSame('2026-10-20T08:15:30.000000Z', $file['created_at']->toJSON());
        $this->assertInstanceOf(CarbonImmutable::class, $file['modified_at']);
        $this->assertSame('2026-10-21T09:45:00.250000Z', $file['modified_at']->toJSON());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::FILE_URL
            && $request->hasHeader('Authorization', 'Bearer token')
            && ! $request->hasHeader('X-Goog-Drive-Resource-Keys'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusableFiles(): array
    {
        return [
            'not a video' => [['mimeType' => 'application/pdf'], 'The file you chose is not a video. Choose a video file.'],
            'trashed' => [['trashed' => true], 'That video is in your Google Drive trash. Choose another video.'],
            'not owned' => [['ownedByMe' => false], 'Choose a video from your own Google Drive.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('unusableFiles')]
    public function test_rejects_a_file_that_is_not_an_owned_untrashed_video(array $overrides, string $message): void
    {
        $connection = $this->connection();
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/files/video-file-1*' => Http::response(self::driveVideo($overrides))]);

        $this->assertSame($message, $this->refusalMessage($connection, 'video-file-1'));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function driveErrors(): array
    {
        return [
            'forbidden' => [403, 'insufficientFilePermissions'],
            'not found' => [404, 'notFound'],
        ];
    }

    #[DataProvider('driveErrors')]
    public function test_drive_error_is_refused_and_logged_without_the_token(int $status, string $reason): void
    {
        $connection = $this->connection();
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/files/video-file-1*' => Http::response(['error' => ['code' => $status, 'message' => 'Drive error', 'errors' => [['reason' => $reason]]]], $status)]);

        $this->assertSame(self::UNAVAILABLE_MESSAGE, $this->refusalMessage($connection, 'video-file-1'));

        Log::shouldHaveReceived('warning')->once()->with('Google Drive video lookup failed.', [
            'operation' => 'files.get',
            'file_id_suffix' => 'file-1',
            'http_status' => $status,
            'google_reason' => $reason,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFileIds(): array
    {
        return [
            'empty' => [''],
            'path traversal' => ['../files'],
            'query string' => ['video-file-1?alt=media'],
            'space' => ['video file'],
        ];
    }

    #[DataProvider('malformedFileIds')]
    public function test_malformed_file_id_is_refused_without_a_request(string $fileId): void
    {
        $connection = $this->connection();
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response(self::driveVideo())]);

        $this->assertSame(self::UNAVAILABLE_MESSAGE, $this->refusalMessage($connection, $fileId));

        Http::assertNothingSent();
    }

    public function test_resource_key_is_sent_in_the_drive_header(): void
    {
        $connection = $this->connection();
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/files/video-file-1*' => Http::response(self::driveVideo(['resourceKey' => '0-resource-key']))]);

        $file = app(GoogleDriveService::class)->videoFile($connection, 'video-file-1', '0-resource-key');

        $this->assertSame('0-resource-key', $file['resource_key']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::FILE_URL
            && $request->hasHeader('X-Goog-Drive-Resource-Keys', 'video-file-1/0-resource-key'));
    }

    public function test_expired_token_is_refreshed_before_reading_the_file(): void
    {
        $connection = $this->connection(['access_token' => 'expired-token', 'refresh_token' => 'refresh-token', 'token_expires_at' => now()->subMinute()]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            'https://www.googleapis.com/drive/v3/files/video-file-1*' => Http::response(self::driveVideo()),
        ]);

        $file = app(GoogleDriveService::class)->videoFile($connection, 'video-file-1');

        $this->assertSame('video-file-1', $file['id']);
        $this->assertSame('fresh-token', $connection->fresh()->access_token);
        Http::assertSentInOrder([
            fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'refresh-token',
            fn (Request $request): bool => $request->url() === self::FILE_URL
                && $request->hasHeader('Authorization', 'Bearer fresh-token'),
        ]);
    }

    public function test_expired_connection_without_a_refresh_token_is_refused_without_reading_drive(): void
    {
        $connection = $this->connection(['token_expires_at' => now()->subMinute()]);
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response(self::driveVideo())]);

        $this->assertSame(self::UNAVAILABLE_MESSAGE, $this->refusalMessage($connection, 'video-file-1'));

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(array $attributes = []): GoogleDriveConnection
    {
        $participant = User::factory()->create(['role' => 'participant']);

        return GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'google_account_id' => 'account',
            'google_email' => 'participant@gmail.com',
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function driveVideo(array $overrides = []): array
    {
        return [
            'id' => 'video-file-1',
            'name' => 'Week 1 demo.mp4',
            'mimeType' => 'video/mp4',
            'size' => '73400320',
            'createdTime' => '2026-10-20T08:15:30.000Z',
            'modifiedTime' => '2026-10-21T09:45:00.250Z',
            'trashed' => false,
            'ownedByMe' => true,
            ...$overrides,
        ];
    }

    private function refusalMessage(GoogleDriveConnection $connection, string $fileId): string
    {
        try {
            app(GoogleDriveService::class)->videoFile($connection, $fileId);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        $this->fail('The Drive video lookup should have been refused.');
    }
}
