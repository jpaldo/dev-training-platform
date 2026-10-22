<?php

namespace App\Models;

use Database\Factories\DailyLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyLog extends Model
{
    /** @use HasFactory<DailyLogFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'activity_id', 'logged_on', 'content'];

    protected function casts(): array
    {
        return ['logged_on' => 'date'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
