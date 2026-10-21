<?php

namespace App\Models;

use App\Enums\ParticipantNotificationPriority;
use App\Enums\ParticipantNotificationType;
use Database\Factories\ParticipantNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantNotification extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'key', 'type', 'title', 'body', 'action_url', 'priority', 'is_read', 'resolved_at'];

    protected static function newFactory(): ParticipantNotificationFactory
    {
        return ParticipantNotificationFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ParticipantNotificationType::class,
            'priority' => ParticipantNotificationPriority::class,
            'is_read' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false)->whereNull('resolved_at');
    }
}
