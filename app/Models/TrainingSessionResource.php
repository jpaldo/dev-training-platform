<?php

namespace App\Models;

use App\Support\GoogleDriveLink;
use Database\Factories\TrainingSessionResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link an admin shares with a training session, for the whole session (`week_number` null) or one week. Whether it
 * is a Google Drive file with an in-app preview is derived from its `url` (`googleDriveLink()`), never stored.
 */
#[Fillable(['training_session_id', 'week_number', 'title', 'url', 'added_by'])]
class TrainingSessionResource extends Model
{
    /** @use HasFactory<TrainingSessionResourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
        ];
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * The Google Drive file the link points at, or null for any other link.
     */
    public function googleDriveLink(): ?GoogleDriveLink
    {
        return GoogleDriveLink::fromUrl($this->url);
    }
}
