<?php

namespace App\Models;

use App\Observers\ParticipantProgressObserver;
use Database\Factories\WeeklyVideoRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant's weekly video for a training week of a training session: a video file in the participant's own
 * Google Drive, chosen with the Google Picker. The app stores the Drive file's ID (and resource key), name, type, size
 * and dates, never the video itself; mentors and participants open it in Drive. On choosing, the app shares the file
 * view only with the company's Google Workspace domain and keeps that Drive permission's ID and when it was shared.
 */
#[ObservedBy([ParticipantProgressObserver::class])]
class WeeklyVideoRecording extends Model
{
    /** @use HasFactory<WeeklyVideoRecordingFactory> */
    use HasFactory;

    protected $fillable = ['training_session_id', 'user_id', 'training_week_id', 'drive_file_id', 'drive_resource_key', 'drive_permission_id', 'name', 'mime_type', 'size_bytes', 'drive_created_at', 'drive_modified_at', 'notes', 'chosen_at', 'shared_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'drive_created_at' => 'datetime',
            'drive_modified_at' => 'datetime',
            'chosen_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }

    /**
     * Whether the app shared the video with the company's Google Workspace domain when it was chosen.
     */
    public function isShared(): bool
    {
        return $this->shared_at !== null;
    }

    /**
     * The video's page in Google Drive, e.g. `https://drive.google.com/file/d/{id}/view?resourcekey={key}`.
     */
    public function driveViewUrl(): string
    {
        return $this->driveFileUrl('view');
    }

    /**
     * The video's embeddable Google Drive preview, e.g. `https://drive.google.com/file/d/{id}/preview`.
     */
    public function drivePreviewUrl(): string
    {
        return $this->driveFileUrl('preview');
    }

    /**
     * The video's size in MB (when Drive reported one) and when it was chosen, e.g. "12.5 MB · chosen Oct 22, 2026 9:30 AM".
     */
    public function detailsLabel(): string
    {
        return collect([
            $this->size_bytes === null ? null : number_format($this->size_bytes / 1048576, 1).' MB',
            $this->chosen_at === null ? null : 'chosen '.$this->chosen_at->format('M j, Y g:i A'),
        ])->filter()->implode(' · ');
    }

    private function driveFileUrl(string $page): string
    {
        $url = 'https://drive.google.com/file/d/'.rawurlencode($this->drive_file_id).'/'.$page;

        return filled($this->drive_resource_key) ? $url.'?'.http_build_query(['resourcekey' => $this->drive_resource_key]) : $url;
    }
}
