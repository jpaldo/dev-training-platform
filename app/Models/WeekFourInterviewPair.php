<?php

namespace App\Models;

use App\Enums\InterviewPairStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeekFourInterviewPair extends Model
{
    protected $fillable = ['participant_one_id', 'participant_two_id', 'assigned_by_id', 'status'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => InterviewPairStatus::class];
    }

    public function participantOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_one_id');
    }

    public function participantTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_two_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(WeekFourPartnerFeedback::class, 'pair_id');
    }

    /**
     * Only active pairs.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', InterviewPairStatus::Active);
    }

    /**
     * Only pairs with the given user as either participant.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $pair): Builder => $pair->where('participant_one_id', $userId)->orWhere('participant_two_id', $userId));
    }

    public static function forParticipant(int $userId): ?self
    {
        return static::query()->active()->involving($userId)->with(['participantOne', 'participantTwo'])->first();
    }

    /** @return Collection<int, self> */
    public static function forParticipantMany(int $userId): Collection
    {
        return static::query()
            ->active()
            ->involving($userId)
            ->with(['participantOne.assignedCoaches', 'participantTwo.assignedCoaches', 'feedback'])
            ->orderBy('id')
            ->get();
    }

    public function partnerFor(int $userId): ?User
    {
        return (int) $this->participant_one_id === $userId ? $this->participantTwo : $this->participantOne;
    }
}
