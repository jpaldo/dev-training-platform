<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\User;

class CoachSessionFilterService
{
    /**
     * The training sessions a coach page's session filter offers, the latest start first: every session for an admin,
     * otherwise the sessions the viewer mentors, each labelled "Title (9 Nov – 6 Dec 2026)" and marked when it is the
     * selected one.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    public function options(User $viewer, int|string|null $selectedSessionId): array
    {
        $trainingSessions = $viewer->hasAnyRole(UserRole::Admin) ? TrainingSession::query() : $viewer->mentoredSessions();

        return $trainingSessions
            ->orderByDesc('training_sessions.starts_on')
            ->orderBy('training_sessions.id')
            ->get(['training_sessions.id', 'training_sessions.title', 'training_sessions.starts_on', 'training_sessions.ends_on'])
            ->map(fn (TrainingSession $trainingSession): array => [
                'value' => (string) $trainingSession->id,
                'label' => $trainingSession->titleWithDates(),
                'is_selected' => (string) $selectedSessionId === (string) $trainingSession->id,
            ])
            ->all();
    }
}
