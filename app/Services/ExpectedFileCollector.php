<?php

namespace App\Services;

use App\Models\GateCriterion;
use App\Models\TrainingWeek;
use App\Support\ChecklistItemCheck;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Builds the list of checklist items a repository check of a week checks in the participant's repository.
 */
class ExpectedFileCollector
{
    /**
     * The week's checked items: the deliverable and session-logs items on its checklist (`TrainingWeek::checklist()`;
     * retired items are left out) in position order that have expected files or GitHub checks. Weekly-video items are
     * never checked, and a week without a checklist has nothing to check. Runs two queries at most, whatever the
     * number of items.
     *
     * @return list<ChecklistItemCheck>
     */
    public function forWeek(TrainingWeek $week): array
    {
        $checklist = $week->checklist()
            ->with(['criteria' => fn (HasMany $items): HasMany => $items
                ->whereIn('criterion_type', [GateCriterion::TYPE_DELIVERABLE, GateCriterion::TYPE_SESSION_LOGS])
                ->onChecklist()
                ->orderBy('position')
                ->orderBy('id')])
            ->first();

        if ($checklist === null) {
            return [];
        }

        return $checklist->criteria
            ->map(fn (GateCriterion $item): ?ChecklistItemCheck => $item->repositoryCheck())
            ->filter()
            ->values()
            ->all();
    }
}
