<?php

namespace App\Models;

use Database\Factories\EvidenceRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceRequirement extends Model
{
    /** @use HasFactory<EvidenceRequirementFactory> */
    use HasFactory;

    protected $fillable = ['activity_requirement_id', 'title', 'description', 'evidence_type', 'is_required', 'minimum_count', 'maximum_count', 'configuration', 'position'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'configuration' => 'array'];
    }

    public function activityRequirement(): BelongsTo
    {
        return $this->belongsTo(ActivityRequirement::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SubmissionEvidence::class);
    }
}
