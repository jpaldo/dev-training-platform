<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeekFourInterviewPair extends Model
{
    protected $fillable = ['participant_one_id', 'participant_two_id', 'assigned_by_id', 'status'];

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

    public static function forParticipant(int $userId): ?self
    {
        return static::query()->where('status', 'active')->where(fn (Builder $query): Builder => $query->where('participant_one_id', $userId)->orWhere('participant_two_id', $userId))->with(['participantOne', 'participantTwo'])->first();
    }

    /** @return Collection<int, self> */
    public static function forParticipantMany(int $userId): Collection
    {
        return static::query()
            ->where('status', 'active')
            ->where(fn (Builder $query): Builder => $query->where('participant_one_id', $userId)->orWhere('participant_two_id', $userId))
            ->with(['participantOne.assignedCoaches', 'participantTwo.assignedCoaches', 'feedback'])
            ->orderBy('id')
            ->get();
    }

    public function partnerFor(int $userId): ?User
    {
        return (int) $this->participant_one_id === $userId ? $this->participantTwo : $this->participantOne;
    }
}
