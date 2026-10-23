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

    /**
     * The mentors a coach page's mentor filter offers an admin, by name: every coach who is a mentor of at least one
     * session, marked when they are the selected one. A coach sees only their own mentees, so they get none.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    public function mentorOptions(User $viewer, int|string|null $selectedMentorId): array
    {
        if (! $viewer->hasAnyRole(UserRole::Admin)) {
            return [];
        }

        return User::query()
            ->where('role', UserRole::Coach)
            ->whereHas('mentoredSessions')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $mentor): array => [
                'value' => (string) $mentor->id,
                'label' => $mentor->name,
                'is_selected' => (string) $selectedMentorId === (string) $mentor->id,
            ])
            ->all();
    }
}
