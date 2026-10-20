<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveEvidenceExport extends Model
{
    public const ENQUEUED = 'enqueued';

    public const IN_PROGRESS = 'in_progress';

    public const RETRYING = 'retrying';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = ['submission_evidence_id', 'drive_file_id', 'destination_folder_id', 'status', 'error', 'last_attempt_at', 'last_synced_at'];

    protected function casts(): array
    {
        return ['last_attempt_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(SubmissionEvidence::class, 'submission_evidence_id');
    }

    public static function forDestination(int $evidenceId, string $destination): self
    {
        $record = static::query()->where('submission_evidence_id', $evidenceId)->where('destination_folder_id', $destination)->first();
        if ($record) {
            return $record;
        }
        $legacy = static::query()->where('submission_evidence_id', $evidenceId)->whereNull('destination_folder_id')->first();
        if ($legacy) {
            $legacy->update(['destination_folder_id' => $destination]);

            return $legacy->fresh();
        }

        return static::create(['submission_evidence_id' => $evidenceId, 'destination_folder_id' => $destination]);
    }
}
