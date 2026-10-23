<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CoachDashboardPageService
{
    private const STAT_LABELS = [
        'participants' => 'PARTICIPANTS',
        'completed' => 'COMPLETED',
        'needs_review' => 'NEEDS REVIEW',
        'needs_revision' => 'NEEDS REVISION',
        'at_risk' => 'AT RISK',
    ];

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the coach dashboard renders: the viewer's headline counts (CoachReportService::dashboardStats()) with
     * their labels and, where a page lists that set, the link to it with its accessible text, and for a coach the
     * "My sessions" list (null for an admin, who is never a session mentor). The page has no single main action, so
     * every link is secondary.
     *
     * @return array{stats: array<string, array{label: string, value: int, url: ?string, link_label: ?string}>, mySessions: ?list<array{title: string, date_range: string, status_label: string, status_tone: string, participant_count_label: string, participants_url: string}>}
     */
    public function showData(User $viewer): array
    {
        $links = $this->statLinks();
        $stats = [];

        foreach ($this->reports->dashboardStats($viewer) as $key => $value) {
            $stats[$key] = [
                'label' => self::STAT_LABELS[$key],
                'value' => $value,
                'url' => $links[$key]['url'] ?? null,
                'link_label' => $links[$key]['link_label'] ?? null,
            ];
        }

        return [
            'stats' => $stats,
            'mySessions' => $viewer->hasAnyRole(UserRole::Coach) ? $this->mySessions($viewer) : null,
        ];
    }

    /**
     * The sessions the coach is a mentor of, the latest start first, each with its dates, status, how many of its
     * participants the coach currently mentors there ("2 participants assigned to you") and the participants list
     * filtered to it.
     *
     * @return list<array{title: string, date_range: string, status_label: string, status_tone: string, participant_count_label: string, participants_url: string}>
     */
    private function mySessions(User $coach): array
    {
        return $coach->mentoredSessions()
            ->select(['training_sessions.id', 'training_sessions.title', 'training_sessions.starts_on', 'training_sessions.ends_on'])
            ->withCount(['mentorAssignments as my_participant_count' => fn (Builder $assignments): Builder => $assignments
                ->current()
                ->where('mentor_id', $coach->id)])
            ->orderByDesc('training_sessions.starts_on')
            ->orderBy('training_sessions.id')
            ->get()
            ->map(fn (TrainingSession $trainingSession): array => [
                'title' => $trainingSession->title,
                'date_range' => $trainingSession->dateRangeLabel(),
                'status_label' => $trainingSession->status()->label(),
                'status_tone' => $trainingSession->status()->tone(),
                'participant_count_label' => $trainingSession->my_participant_count.' '.Str::plural('participant', $trainingSession->my_participant_count).' assigned to you',
                'participants_url' => route('coach.participants', ['session' => $trainingSession->id]),
            ])
            ->all();
    }

    /**
     * The page listing each stat's set, keyed by stat: the participants list, the review queue (submissions awaiting
     * review are listed there with those needing revision), the queue filtered to needs revision and the progress
     * report's at-risk table. No page lists the completed participants, so that stat has no link.
     *
     * @return array<string, array{url: string, link_label: string}>
     */
    private function statLinks(): array
    {
        return [
            'participants' => ['url' => route('coach.participants'), 'link_label' => 'open the participants list'],
            'needs_review' => ['url' => route('coach.reviews'), 'link_label' => 'open the review queue'],
            'needs_revision' => [
                'url' => route('coach.reviews', ['status' => SubmissionStatus::NeedsRevision->value]),
                'link_label' => 'open the review queue filtered to needs revision',
            ],
            'at_risk' => ['url' => route('coach.reports').'#at-risk-title', 'link_label' => 'open the at-risk participants in the progress report'],
        ];
    }
}
