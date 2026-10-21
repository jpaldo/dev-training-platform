<?php

namespace App\Models;

use App\Enums\GoogleDriveSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveSync extends Model
{
    protected $fillable = ['user_id', 'destination_folder_id', 'destination_resource_key', 'destination_drive_id', 'status', 'trigger', 'needs_follow_up', 'total_components', 'successful_components', 'failed_components', 'skipped_components', 'component_results', 'error', 'started_at', 'completed_at', 'last_successful_sync_at'];

    protected function casts(): array
    {
        return [
            'status' => GoogleDriveSyncStatus::class,
            'needs_follow_up' => 'boolean',
            'component_results' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, GoogleDriveSyncStatus::active(), true);
    }
}
