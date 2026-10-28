<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CoachProgramFilterService
{
    /**
     * The curricula a coach page's curriculum filter offers, the latest first week start first (unscheduled curricula
     * last; both MySQL and SQLite sort nulls last in descending order), then by name: every curriculum for an admin,
     * otherwise the curricula the viewer mentors, each labelled "Name (9 Nov – 6 Dec 2026)" ("Name (Not scheduled)"
     * without dates) and marked when it is the selected one. One query.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    public function options(User $viewer, int|string|null $selectedProgramId): array
    {
        $programs = $viewer->hasAnyRole(UserRole::Admin) ? Program::query() : $viewer->mentoredPrograms();

        return $programs
            ->select(['programs.id', 'programs.name'])
            ->withScheduleBounds()
            ->orderByDesc('schedule_starts_on')
            ->orderBy('programs.name')
            ->orderBy('programs.id')
            ->get()
            ->map(fn (Program $program): array => [
                'value' => (string) $program->id,
                'label' => "{$program->name} ({$program->dateRangeLabel()})",
                'is_selected' => (string) $selectedProgramId === (string) $program->id,
            ])
            ->all();
    }

    /**
     * The participants a coach page's participant filter offers, by name: every participant for an admin, otherwise
     * those the viewer currently mentors in at least one curriculum, marked when they are the selected one.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    public function participantOptions(User $viewer, int|string|null $selectedParticipantId): array
    {
        return User::query()
            ->where('role', UserRole::Participant)
            ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query
                ->whereHas('currentMentors', fn (Builder $mentors): Builder => $mentors->whereKey($viewer->id)))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $participant): array => [
                'value' => (string) $participant->id,
                'label' => $participant->name,
                'is_selected' => (string) $selectedParticipantId === (string) $participant->id,
            ])
            ->all();
    }

    /**
     * The mentors a coach page's mentor filter offers an admin, by name: every coach who is a mentor of at least one
     * curriculum, marked when they are the selected one. A coach sees only their own mentees, so they get none.
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
            ->whereHas('mentoredPrograms')
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
