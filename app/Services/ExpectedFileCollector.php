<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\TrainingWeek;
use App\Support\ExpectedFile;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Builds the list of files a repository check of a week expects in the participant's repository.
 */
class ExpectedFileCollector
{
    /**
     * The week's expected files: those of its active activities in position order, then those of the session-logs
     * item on its checklist (`TrainingWeek::checklist()`; a retired item is left out). A week without a checklist has
     * only its activities' files. Runs three queries at most, whatever the number of activities.
     *
     * @return list<ExpectedFile>
     */
    public function forWeek(TrainingWeek $week): array
    {
        $activities = $week->activities()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title', 'configuration']);

        $checklist = $week->checklist()
            ->with(['criteria' => fn (HasMany $items): HasMany => $items
                ->where('criterion_type', GateCriterion::TYPE_SESSION_LOGS)
                ->onChecklist()
                ->orderBy('position')
                ->orderBy('id')])
            ->first();

        return [
            ...$activities->flatMap(fn (Activity $activity): array => $activity->expectedFiles())->all(),
            ...($checklist?->criteria ?? collect())->flatMap(fn (GateCriterion $item): array => $item->expectedFiles())->all(),
        ];
    }
}
