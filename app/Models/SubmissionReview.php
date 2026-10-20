<?php

namespace App\Models;

use App\Observers\ParticipantProgressObserver;
use Database\Factories\SubmissionReviewFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([ParticipantProgressObserver::class])]
class SubmissionReview extends Model
{
    /** @use HasFactory<SubmissionReviewFactory> */
    use HasFactory;

    protected $fillable = ['submission_version_id', 'reviewer_id', 'decision', 'comments', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function submissionVersion(): BelongsTo
    {
        return $this->belongsTo(SubmissionVersion::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
