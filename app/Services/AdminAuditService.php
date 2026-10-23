<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;

class AdminAuditService
{
    /**
     * Record an admin action in `admin_audit_logs`. Training session actions put the session's id in the metadata as
     * `training_session_id`.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(User $actor, string $action, ?User $target = null, array $metadata = []): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'actor_id' => $actor->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
