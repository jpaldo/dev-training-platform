<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSession extends Model
{
    protected $fillable = ['user_id', 'check_in_at', 'check_out_at', 'total_seconds'];

    protected function casts(): array
    {
        return ['check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'total_seconds' => 'integer'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOpen(): bool
    {
        return $this->check_in_at !== null && $this->check_out_at === null;
    }

    /**
     * The stored total, or the seconds from check-in to check-out (to now while the session is still open).
     */
    public function durationSeconds(): int
    {
        return $this->total_seconds ?? (int) max(0, $this->check_in_at->diffInSeconds($this->check_out_at ?? now()));
    }

    public function totalHours(): float
    {
        return round($this->durationSeconds() / 3600, 2);
    }
}
