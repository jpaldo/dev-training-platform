<?php

namespace Database\Factories;

use Closure;

/**
 * Numbers factory rows 1, 2, 3… within their parent instead of picking a random position,
 * so rows created for the same parent never collide on a unique (parent, position) index.
 */
trait AssignsNextPosition
{
    /**
     * Highest position this factory instance has handed out per parent id, covering rows
     * of a counted batch that are made before any of them is saved.
     *
     * @var array<string, int>
     */
    protected array $highestAssignedPositions = [];

    /**
     * Resolve the position after the highest one stored, or already handed out, for the row's parent.
     *
     * @return Closure(array<string, mixed>): int
     */
    protected function nextPositionWithin(string $parentKey): Closure
    {
        return function (array $attributes) use ($parentKey): int {
            $parentId = $attributes[$parentKey] ?? null;
            $assignedKey = (string) $parentId;
            $highestStoredPosition = (int) $this->newModel()->newQueryWithoutScopes()->where($parentKey, $parentId)->max('position');
            $position = max($highestStoredPosition, $this->highestAssignedPositions[$assignedKey] ?? 0) + 1;

            $this->highestAssignedPositions[$assignedKey] = $position;

            return $position;
        };
    }
}
