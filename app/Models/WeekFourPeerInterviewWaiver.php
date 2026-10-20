<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekFourPeerInterviewWaiver extends Model
{
    protected $fillable = ['participant_id', 'activity_id', 'authorized_by_id', 'reason', 'waived_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['waived_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_id');
    }
}
