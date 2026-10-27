<?php

namespace App\Services;

use App\Enums\TrainingSessionStatus;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CurriculumPageService
{
    /**
     * Everything the programs list renders: every program by name, each with whether it is active, its number of
     * weeks and of training sessions, and a link to its page (one query).
     *
     * @return array{programs: list<array{name: string, status_label: string, status_tone: string, weeks_count: int, sessions_count: int, url: string}>}
     */
    public function indexData(): array
    {
        return [
            'programs' => Program::query()
                ->withCount(['weeks', 'trainingSessions'])
                ->orderBy('name')
                ->get()
                ->map(fn (Program $program): array => [
                    'name' => $program->name,
                    'status_label' => $program->is_active ? 'Active' : 'Inactive',
                    'status_tone' => $program->is_active ? 'success' : 'neutral',
                    'weeks_count' => (int) $program->weeks_count,
                    'sessions_count' => (int) $program->training_sessions_count,
                    'url' => route('admin.programs.show', $program),
                ])
                ->all(),
        ];
    }

    /**
     * Everything the program page renders: the program's name and description, the link back to the list, the
     * sessions notice (one query) and the weeks in number order, each with whether it is active, its number of active
     * activities and of items on its checklist (the required items of its active Gates), read in one query.
     *
     * @return array{name: string, description: ?string, indexUrl: string, notice: array{sessions: list<array{title: string, dates: string, status_label: string, status_tone: string, url: string}>, has_active: bool, tone: string, message: string}, weeks: list<array{number: int, label: string, title: string, status_label: string, status_tone: string, active_activity_count: int, checklist_item_count: int}>}
     */
    public function programData(Program $program): array
    {
        return [
            'name' => $program->name,
            'description' => $program->description,
            'indexUrl' => route('admin.programs.index'),
            'notice' => $this->sessionsNotice($program),
            'weeks' => $program->weeks()
                ->withCount([
                    'activities as active_activities_count' => fn (Builder $activities): Builder => $activities->where('is_active', true),
                    'checklistItems as checklist_items_count' => fn (Builder $items): Builder => $items
                        ->where('training_gates.is_active', true)
                        ->where('gate_criteria.is_required', true),
                ])
                ->orderBy('week_number')
                ->get()
                ->map(fn (TrainingWeek $week): array => [
                    'number' => $week->week_number,
                    'label' => "Week {$week->week_number}",
                    'title' => $week->title,
                    'status_label' => $week->is_active ? 'Active' : 'Inactive',
                    'status_tone' => $week->is_active ? 'success' : 'neutral',
                    'active_activity_count' => (int) $week->active_activities_count,
                    'checklist_item_count' => (int) $week->checklist_items_count,
                ])
                ->all(),
        ];
    }

    /**
     * The notice that tells an admin who sees the program's content: the training sessions that use the program,
     * newest start date first, each with its dates, status badge and dashboard link; whether one of them is active
     * (the notice is then a warning rather than information); and the message that counts them by status, or says
     * that no session uses the program yet (one query).
     *
     * @return array{sessions: list<array{title: string, dates: string, status_label: string, status_tone: string, url: string}>, has_active: bool, tone: string, message: string}
     */
    public function sessionsNotice(Program $program): array
    {
        $sessions = $program->trainingSessions()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
        $countsByStatus = $sessions->countBy(fn (TrainingSession $session): string => $session->status()->value);
        $hasActive = $countsByStatus->has(TrainingSessionStatus::Active->value);

        return [
            'sessions' => $sessions
                ->map(fn (TrainingSession $session): array => [
                    'title' => $session->title,
                    'dates' => $session->dateRangeLabel(),
                    'status_label' => $session->status()->label(),
                    'status_tone' => $session->status()->tone(),
                    'url' => route('admin.sessions.show', $session),
                ])
                ->all(),
            'has_active' => $hasActive,
            'tone' => $hasActive ? 'warning' : 'info',
            'message' => $sessions->isEmpty()
                ? 'No training session uses this program yet.'
                : sprintf(
                    'Used by %d training %s (%d active, %d upcoming, %d completed). Changes show to their participants and mentors right away, including in completed sessions.',
                    $sessions->count(),
                    Str::plural('session', $sessions->count()),
                    $countsByStatus->get(TrainingSessionStatus::Active->value, 0),
                    $countsByStatus->get(TrainingSessionStatus::Upcoming->value, 0),
                    $countsByStatus->get(TrainingSessionStatus::Completed->value, 0),
                ),
        ];
    }
}
