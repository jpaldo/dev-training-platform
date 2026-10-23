<?php

namespace App\Models;

use App\Enums\TrainingSessionResourceType;
use Database\Factories\TrainingSessionResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file or link an admin shares with a training session, for the whole session (`week_number` null) or one week. A
 * file is kept on a private disk (`disk`, `path`, `original_filename`, `mime_type`, `size_bytes`); a link has a `url`.
 */
#[Fillable(['training_session_id', 'week_number', 'title', 'type', 'url', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by'])]
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
            'type' => TrainingSessionResourceType::class,
            'week_number' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isFile(): bool
    {
        return $this->type === TrainingSessionResourceType::File;
    }
}
