<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;

class AdminAuditService
{
    /**
     * Record an admin action in `admin_audit_logs`. Curriculum actions put the curriculum's id in the metadata as
     * `program_id`: its creation, offering and content edits (`curriculum_*`, e.g. `curriculum_created`,
     * `curriculum_duplicated` (which also stores `source_program_id`), `curriculum_offered|withdrawn`,
     * `curriculum_week_updated`, `curriculum_scheduled`) and the people and links in it,
     * `program_participant_added|removed` (ProgramEnrollmentService), `program_mentor_added|removed|assigned`
     * (MentorAssignmentService) and `program_resource_added|renamed|removed` (ProgramResourceService, which also stores
     * `program_resource_id` and `title`).
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
