<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyVideoRecording extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'training_week_id', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'notes', 'uploaded_at', 'drive_file_id', 'drive_destination_folder_id'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }
}
