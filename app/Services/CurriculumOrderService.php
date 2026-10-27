<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moves a curriculum item (an activity, a submission field or a checklist item) one place up or down among its
 * siblings by swapping `position` with its neighbour. Siblings are expected to have distinct positions.
 */
class CurriculumOrderService
{
    /**
     * Swap the item's position with the sibling just before it ("up") or just after it ("down"), ordered by position
     * then id, and return whether it moved. An item already first (up) or last (down) does not move and nothing is
     * saved. The item is parked on one past the siblings' highest position before the swap, so a unique index on the
     * position (such as `activities`' (`training_week_id`, `position`)) never sees two rows on the same position; for
     * that, `$siblings` must cover every row of the unique index's scope. Positions are unsigned, so the item is never
     * parked on a negative one.
     *
     * @param  Builder<Model>  $siblings
     * @param  'up'|'down'  $direction
     */
    public function move(Model $item, Builder $siblings, string $direction): bool
    {
        $isMovingUp = $direction === 'up';
        $neighbour = $siblings->clone()
            ->whereKeyNot($item->getKey())
            ->where('position', $isMovingUp ? '<' : '>', $item->position)
            ->orderBy('position', $isMovingUp ? 'desc' : 'asc')
            ->orderBy($item->getKeyName(), $isMovingUp ? 'desc' : 'asc')
            ->first();

        if ($neighbour === null) {
            return false;
        }

        DB::transaction(function () use ($item, $neighbour, $siblings): void {
            $itemPosition = $item->position;
            $neighbourPosition = $neighbour->position;

            $item->position = (int) $siblings->clone()->max('position') + 1;
            $item->save();

            $neighbour->position = $itemPosition;
            $neighbour->save();

            $item->position = $neighbourPosition;
            $item->save();
        });

        return true;
    }
}
