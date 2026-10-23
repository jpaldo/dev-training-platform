<?php

namespace App\Models;

use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(TrainingWeek::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    /**
     * The numbers of the program's active weeks that have no checklist to review: no active Gate with at least one
     * required item. Every active week of an active program needs one, so the mentor has something to tick.
     *
     * @return Collection<int, int>
     */
    public function weekNumbersWithoutChecklist(): Collection
    {
        return $this->weeks()
            ->where('is_active', true)
            ->whereDoesntHave('gates', fn (Builder $gates): Builder => $gates
                ->where('is_active', true)
                ->whereHas('criteria', fn (Builder $criteria): Builder => $criteria->where('is_required', true)))
            ->orderBy('week_number')
            ->pluck('week_number');
    }
}
