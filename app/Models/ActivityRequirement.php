<?php

namespace App\Models;

use Database\Factories\ActivityRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityRequirement extends Model
{
    /** @use HasFactory<ActivityRequirementFactory> */
    use HasFactory;

    protected $fillable = ['activity_id', 'participant_path', 'requirement_type', 'title', 'description', 'is_required', 'position', 'configuration'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'configuration' => 'array'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function evidenceRequirements(): HasMany
    {
        return $this->hasMany(EvidenceRequirement::class);
    }
}
