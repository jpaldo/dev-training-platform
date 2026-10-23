<?php

namespace App\Services;

use App\Models\Program;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AdminTrainingSessionPageService
{
    private const SESSIONS_PER_PAGE = 20;

    /**
     * Everything the training session list renders: the Create Session link and every session, newest start date
     * first, 20 per page. Each row shows the title, program, date range, status and participant count, and links to
     * the session's edit form.
     *
     * @return array{createUrl: string, sessions: LengthAwarePaginator<int, array{title: string, program: string, date_range: string, status_label: string, status_tone: string, participant_count: int, edit_url: string}>}
     */
    public function indexData(): array
    {
        return [
            'createUrl' => route('admin.sessions.create'),
            'sessions' => TrainingSession::query()
                ->with('program:id,name')
                ->withCount('participants')
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->paginate(self::SESSIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (TrainingSession $session): array => $this->sessionRow($session)),
        ];
    }

    /**
     * Everything the create or edit session form renders: the active programs with their number of active weeks (the
     * most a session of that program can have) and, when editing, the session's current details. Once participants have
     * joined, the program is locked: the form shows it but submits the current one. Saving the session is the page's
     * single main action.
     *
     * @return array{title: string, formAction: string, formMethod: ?string, submitLabel: string, cancelUrl: string, programOptions: list<array{value: string, label: string}>, programId: string, isProgramLocked: bool, sessionTitle: ?string, weeksCount: ?int, startsOn: ?string, endsOn: ?string}
     */
    public function formData(?TrainingSession $trainingSession = null): array
    {
        return [
            'title' => $trainingSession ? 'Edit Training Session' : 'Create Training Session',
            'formAction' => $trainingSession ? route('admin.sessions.update', $trainingSession) : route('admin.sessions.store'),
            'formMethod' => $trainingSession ? 'PUT' : null,
            'submitLabel' => $trainingSession ? 'Save Session' : 'Create Session',
            'cancelUrl' => route('admin.sessions.index'),
            'programOptions' => Program::query()
                ->select(['id', 'name'])
                ->where('is_active', true)
                ->withCount(['weeks' => fn (Builder $weeks): Builder => $weeks->where('is_active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Program $program): array => [
                    'value' => (string) $program->id,
                    'label' => "{$program->name} ({$program->weeks_count} ".Str::plural('week', $program->weeks_count).')',
                ])
                ->all(),
            'programId' => (string) $trainingSession?->program_id,
            'isProgramLocked' => $trainingSession?->hasParticipants() ?? false,
            'sessionTitle' => $trainingSession?->title,
            'weeksCount' => $trainingSession?->weeks_count,
            'startsOn' => $trainingSession?->starts_on->toDateString(),
            'endsOn' => $trainingSession?->ends_on->toDateString(),
        ];
    }

    /**
     * One list row. The date range names the year once when both dates fall in it ("9 Nov – 6 Dec 2026").
     *
     * @return array{title: string, program: string, date_range: string, status_label: string, status_tone: string, participant_count: int, edit_url: string}
     */
    private function sessionRow(TrainingSession $session): array
    {
        $status = $session->status();
        $startFormat = $session->starts_on->year === $session->ends_on->year ? 'j M' : 'j M Y';

        return [
            'title' => $session->title,
            'program' => $session->program->name,
            'date_range' => $session->starts_on->format($startFormat).' – '.$session->ends_on->format('j M Y'),
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'participant_count' => (int) $session->participants_count,
            'edit_url' => route('admin.sessions.edit', $session),
        ];
    }
}
