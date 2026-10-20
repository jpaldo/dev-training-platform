<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewScheduler extends Model
{
    public const MOCK = 'mock';

    public const FINAL_REASSESSMENT = 'final_reassessment';

    public const BOTH = 'both';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'interview_type',
        'user_id',
        'full_name',
        'email',
        'booking_url',
        'mock_booking_url',
        'final_booking_url',
        'is_active',
        'display_order',
        'approval_status',
        'submitted_publicly',
        'approved_by',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'display_order' => 'integer', 'submitted_publicly' => 'boolean', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
