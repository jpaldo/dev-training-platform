<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Observers\ParticipantProgressObserver;
use Database\Factories\SubmissionVersionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([ParticipantProgressObserver::class])]
class SubmissionVersion extends Model
{
    /** @use HasFactory<SubmissionVersionFactory> */
    use HasFactory;

    protected $fillable = ['activity_submission_id', 'version_number', 'status', 'field_values', 'participant_note', 'submitted_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => SubmissionStatus::class, 'field_values' => 'array', 'submitted_at' => 'datetime'];
    }

    public function isDraft(): bool
    {
        return $this->status === SubmissionStatus::Draft;
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ActivitySubmission::class, 'activity_submission_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SubmissionEvidence::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SubmissionReview::class);
    }

    public function activityScore(): HasOne
    {
        return $this->hasOne(ActivityScore::class);
    }

    /**
     * The version's published score, the one every score surface and export reads; a draft score counts as no score.
     * The scoring form edits the stored row through `activityScore()`.
     *
     * @return HasOne<ActivityScore, $this>
     */
    public function publishedActivityScore(): HasOne
    {
        return $this->hasOne(ActivityScore::class)->published();
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(SubmissionInteraction::class)->orderBy('sequence');
    }

    public function scoreSuggestion(): HasOne
    {
        return $this->hasOne(ActivityScoreSuggestion::class);
    }
}
