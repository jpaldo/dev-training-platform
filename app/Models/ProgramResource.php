<?php

namespace App\Models;

use App\Support\GoogleDriveLink;
use Database\Factories\ProgramResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link an admin shares with a curriculum, for the whole curriculum (`week_number` null) or one week. Whether it is a
 * Google Drive file with an in-app preview is derived from its `url` (`googleDriveLink()`), never stored.
 */
#[Fillable(['program_id', 'week_number', 'title', 'url', 'added_by'])]
class ProgramResource extends Model
{
    /** @use HasFactory<ProgramResourceFactory> */
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

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
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
