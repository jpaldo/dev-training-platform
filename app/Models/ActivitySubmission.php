<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Observers\ParticipantProgressObserver;
use Database\Factories\ActivitySubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([ParticipantProgressObserver::class])]
class ActivitySubmission extends Model
{
    /** @use HasFactory<ActivitySubmissionFactory> */
    use HasFactory;

    protected $fillable = ['activity_id', 'user_id', 'attempt_number', 'status', 'submitted_at', 'completed_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'submitted_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function isDraft(): bool
    {
        return $this->status === SubmissionStatus::Draft;
    }

    public function needsRevision(): bool
    {
        return $this->status === SubmissionStatus::NeedsRevision;
    }

    public function isFailed(): bool
    {
        return $this->status === SubmissionStatus::Failed;
    }

    /**
     * Whether a mentor or admin sent the handed-in attempt back for changes: it needs revision, or the participant has
     * started its revision draft (a draft attempt that was handed in before). A retry attempt or a first draft is not
     * reopened.
     */
    public function isReopened(): bool
    {
        return $this->needsRevision() || ($this->isDraft() && $this->submitted_at !== null);
    }

    /**
     * Whether the participant handed the attempt in and it was neither sent back nor failed.
     */
    public function isHandedIn(): bool
    {
        return in_array($this->status, SubmissionStatus::handedIn(), true);
    }

    /**
     * Whether the attempt waits for a coach decision (submitted, resubmitted or under review).
     */
    public function isAwaitingReview(): bool
    {
        return in_array($this->status, SubmissionStatus::awaitingReview(), true);
    }

    /**
     * Whether a coach can claim the attempt for review (submitted or resubmitted).
     */
    public function isClaimable(): bool
    {
        return in_array($this->status, SubmissionStatus::claimable(), true);
    }

    /**
     * Whether the coach accepted the attempt (approved or completed).
     */
    public function isAccepted(): bool
    {
        return in_array($this->status, SubmissionStatus::accepted(), true);
    }

    /**
     * Whether this is an attempt at the activity.
     */
    public function isForActivity(Activity $activity): bool
    {
        return $this->activity_id === $activity->id;
    }

    /**
     * Limit the query to attempts whose participant the given coach currently mentors in the curriculum of the
     * attempt's activity (a `mentor_assignments` row for that participant and curriculum naming them, not ended).
     */
    public function scopeCurrentlyMentoredBy(Builder $query, User $mentor): Builder
    {
        return $query->whereHas('activity.week', fn (Builder $weeks): Builder => $weeks->whereExists(
            MentorAssignment::query()->currentForMentorOfRow($mentor, $query, 'training_weeks.program_id'),
        ));
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class);
    }

    /**
     * The attempt's version with the highest version number.
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(SubmissionVersion::class)->latestOfMany('version_number');
    }
}
