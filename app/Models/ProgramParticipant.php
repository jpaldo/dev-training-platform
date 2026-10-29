<?php

namespace App\Models;

use App\Observers\ParticipantProgressObserver;
use App\Rules\GitHubUrl;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A participant's membership of a curriculum (`program_participants`), once per curriculum and for every one of its
 * weeks, with the GitHub repository they added for it (`repository_url`, null until then). Removing a participant
 * deletes the row; their work stays with its activity, Gate or week, so adding them again restores access. Joining and
 * leaving queue the participant's notification sync (ParticipantProgressObserver).
 */
#[ObservedBy([ParticipantProgressObserver::class])]
class ProgramParticipant extends Pivot
{
    /**
     * The table, so the model can also be queried on its own.
     *
     * @var string
     */
    protected $table = 'program_participants';

    /**
     * The table has its own auto-incrementing id.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /**
     * Limit the query to the participant's membership of the curriculum (at most one row).
     */
    public function scopeForParticipantInProgram(Builder $query, int $programId, int $userId): Builder
    {
        return $query->where('program_id', $programId)->where('user_id', $userId);
    }

    /**
     * Limit the query to memberships where the given coach is the participant's current mentor in the curriculum (a
     * `mentor_assignments` row for that participant and curriculum naming them, not ended).
     */
    public function scopeCurrentlyMentoredBy(Builder $query, User $mentor): Builder
    {
        return $query->whereExists(
            MentorAssignment::query()->currentForMentorOfRow($mentor, $query, 'program_participants.program_id'),
        );
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The admin who added the participant; null when the participant joined themselves.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * The owner and name of the participant's GitHub repository, read from `repository_url`: the first two path
     * segments of a GitHubUrl link, without a `.git` clone suffix, so a trailing slash, a deeper path (a branch, file or
     * pull request), a query or a fragment is ignored. Null when no link is set, the link is not on GitHub, or it names
     * no repository.
     *
     * @return array{owner: string, repo: string}|null
     */
    public function repositoryOwnerAndName(): ?array
    {
        $url = $this->repository_url;

        if (! GitHubUrl::accepts($url)) {
            return null;
        }

        $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));

        if (count($segments) < 2) {
            return null;
        }

        $repo = preg_replace('~\.git\z~i', '', $segments[1]);

        if (preg_match('~\A[A-Za-z0-9._-]+\z~', $repo) !== 1 || $repo === '.' || $repo === '..') {
            return null;
        }

        return ['owner' => $segments[0], 'repo' => $repo];
    }
}
