<?php

namespace App\Models;

use App\Enums\RepositoryCheckStatus;
use Database\Factories\RepositoryCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A check of a participant's GitHub repository for a training week of a curriculum, started by a mentor or admin and
 * run by `RunRepositoryCheckJob`. It keeps a snapshot of the repository link and of the week's expected files, the ref
 * the mentor typed and what it resolved to, the deterministic findings (present or missing per expected file), the
 * validated AI assessment (or `assessment_note` saying why there is none), and its timings. The curriculum is reached
 * through the week; `requested_by` is null once that user is deleted.
 */
#[Fillable(['user_id', 'training_week_id', 'requested_by', 'status', 'repository_url', 'ref_input', 'resolved_ref', 'expected_files', 'findings', 'assessment', 'assessment_note', 'error_message', 'provider', 'model', 'github_requests', 'queued_at', 'started_at', 'finished_at'])]
class RepositoryCheck extends Model
{
    /** @use HasFactory<RepositoryCheckFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RepositoryCheckStatus::class,
            'resolved_ref' => 'array',
            'expected_files' => 'array',
            'findings' => 'array',
            'assessment' => 'array',
            'github_requests' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Limit the query to the checks of the participant's repository for the week.
     */
    public function scopeForParticipantWeek(Builder $query, User $participant, TrainingWeek $week): Builder
    {
        return $query->where('user_id', $participant->id)->where('training_week_id', $week->id);
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
     * The mentor or admin who started the check; null once that user is deleted.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Whether the check waits for the queue worker.
     */
    public function isQueued(): bool
    {
        return $this->status === RepositoryCheckStatus::Queued;
    }

    /**
     * Whether the queue worker is running the check.
     */
    public function isRunning(): bool
    {
        return $this->status === RepositoryCheckStatus::Running;
    }

    /**
     * Whether the check has ended, completed or failed.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, RepositoryCheckStatus::finished(), true);
    }
}
