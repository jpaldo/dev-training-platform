<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekFourPartnerFeedback extends Model
{
    protected $table = 'week_four_partner_feedback';

    protected $fillable = ['pair_id', 'author_id', 'recipient_id', 'question_number', 'content'];

    public function pair(): BelongsTo
    {
        return $this->belongsTo(WeekFourInterviewPair::class, 'pair_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
