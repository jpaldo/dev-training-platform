<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveSync extends Model
{
    public const ENQUEUED = 'enqueued';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const FAILED = 'failed';

    protected $fillable = ['user_id', 'destination_folder_id', 'destination_resource_key', 'destination_drive_id', 'status', 'trigger', 'needs_follow_up', 'total_components', 'successful_components', 'failed_components', 'skipped_components', 'component_results', 'error', 'started_at', 'completed_at', 'last_successful_sync_at'];

    protected function casts(): array
    {
        return ['needs_follow_up' => 'boolean', 'component_results' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'last_successful_sync_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
