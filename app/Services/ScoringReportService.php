<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ScoringReportService
{
    /**
     * The submitted versions the viewer may see that match the filters: every one for an admin, otherwise those whose
     * participant the viewer currently mentors in the submission's session. The `mentor` filter keeps the versions
     * whose participant that coach currently mentors in the submission's session. Each submission is loaded with its
     * training session and its current mentor's name (`mentor_name`, null when not assigned).
     *
     * @param  array{participant?: int|string|null, session?: int|string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, score_status?: ?string, band?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Builder<SubmissionVersion>
     */
    public function versions(User $viewer, array $filters): Builder
    {
        $mentor = $this->mentorFilter($filters);

        return SubmissionVersion::query()->where('status', '!=', SubmissionStatus::Draft)
            ->with([
                'submission' => fn (Relation $submissions): Relation => $submissions
                    ->select('activity_submissions.*')
                    ->addSelect(['mentor_name' => MentorAssignment::query()->currentMentorNameOfRow($submissions->getQuery())]),
                'submission.participant',
                'submission.trainingSession:id,title,starts_on,ends_on',
                'submission.activity.week',
                'publishedActivityScore.scorer',
                'publishedActivityScore.secondReader',
                'publishedActivityScore.dimensions',
            ])
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $q): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->currentlyMentoredBy($viewer)))
            ->when($mentor, fn (Builder $q, User $coach): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->currentlyMentoredBy($coach)))
            ->when($filters['session'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->where('training_session_id', $v)))
            ->when($filters['participant'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->where('user_id', $v)))
            ->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission.activity.week', fn (Builder $w): Builder => $w->where('week_number', $v)))
            ->when($filters['activity'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('submission', fn (Builder $s): Builder => $s->where('activity_id', $v)))
            ->when(($filters['score_status'] ?? null) === 'scored', fn (Builder $q): Builder => $q->whereHas('publishedActivityScore'))
            ->when(($filters['score_status'] ?? null) === 'unscored', fn (Builder $q): Builder => $q->whereDoesntHave('publishedActivityScore'))
            ->when($filters['band'] ?? null, fn (Builder $q, string $v): Builder => $q->whereHas('publishedActivityScore', fn (Builder $s): Builder => $s->where('performance_band', $v)))
            ->when($filters['from'] ?? null, fn (Builder $q, string $v): Builder => $q->whereDate('submitted_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v): Builder => $q->whereDate('submitted_at', '<=', $v));
    }

    /**
     * The training session memberships of participants the viewer may see that match the filters, one per
     * participant and session: every one for an admin, otherwise those where the viewer is the participant's current
     * mentor. The work filters (`week`, `activity`, `score_status`, `band`) look at the participant's attempts in the
     * membership's session only. Each membership has its current mentor's name (`mentor_name`, null when not
     * assigned), the participant and the session; they are ordered by participant name, then the latest session first.
     *
     * @param  array{search?: ?string, participant?: int|string|null, session?: int|string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, score_status?: ?string, band?: ?string}  $filters
     * @return Builder<TrainingSessionParticipant>
     */
    public function memberships(User $viewer, array $filters): Builder
    {
        $mentor = $this->mentorFilter($filters);
        $memberships = TrainingSessionParticipant::query();

        return $memberships
            ->select(['id', 'training_session_id', 'user_id'])
            ->addSelect(['mentor_name' => MentorAssignment::query()->currentMentorNameOfRow($memberships)])
            ->whereHas('participant', fn (Builder $participants): Builder => $participants
                ->where('role', UserRole::Participant)
                ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $matches): Builder => $matches
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
            ->when($mentor, fn (Builder $query, User $coach): Builder => $query->currentlyMentoredBy($coach))
            ->when($filters['session'] ?? null, fn (Builder $query, int|string $trainingSessionId): Builder => $query->where('training_session_id', $trainingSessionId))
            ->when($filters['participant'] ?? null, fn (Builder $query, int|string $participantId): Builder => $query->where('user_id', $participantId))
            ->when($filters['week'] ?? null, fn (Builder $query, int|string $week): Builder => $query->whereExists($this->attemptsOfMembership()->whereHas(
                'activity.week',
                fn (Builder $weeks): Builder => $weeks->where('week_number', $week),
            )))
            ->when($filters['activity'] ?? null, fn (Builder $query, int|string $activity): Builder => $query->whereExists($this->attemptsOfMembership()->where('activity_id', $activity)))
            ->when(($filters['score_status'] ?? null) === 'scored', fn (Builder $query): Builder => $query->whereExists($this->attemptsOfMembership()->whereHas('versions.publishedActivityScore')))
            ->when(($filters['score_status'] ?? null) === 'unscored', fn (Builder $query): Builder => $query->whereNotExists($this->attemptsOfMembership()->whereHas('versions.publishedActivityScore')))
            ->when($filters['band'] ?? null, fn (Builder $query, string $band): Builder => $query->whereExists($this->attemptsOfMembership()->whereHas(
                'versions.publishedActivityScore',
                fn (Builder $scores): Builder => $scores->where('performance_band', $band),
            )))
            ->with([
                'participant:id,name,email,employee_id',
                'trainingSession:id,program_id,title,weeks_count,starts_on,ends_on',
            ])
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'training_session_participants.user_id'))
            ->orderByDesc(TrainingSession::query()->select('starts_on')->whereColumn('training_sessions.id', 'training_session_participants.training_session_id'))
            ->orderBy('id');
    }

    /**
     * The attempts of the outer query's membership: its participant's submissions in its session.
     *
     * @return Builder<ActivitySubmission>
     */
    private function attemptsOfMembership(): Builder
    {
        return ActivitySubmission::query()
            ->whereColumn('activity_submissions.user_id', 'training_session_participants.user_id')
            ->whereColumn('activity_submissions.training_session_id', 'training_session_participants.training_session_id');
    }

    /**
     * The coach chosen in the `mentor` filter, or null without one.
     *
     * @param  array{mentor?: int|string|null}  $filters
     */
    private function mentorFilter(array $filters): ?User
    {
        $mentorId = $filters['mentor'] ?? null;

        return $mentorId ? User::query()->find($mentorId) : null;
    }
}
