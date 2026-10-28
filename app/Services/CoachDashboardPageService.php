<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Program;
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
     * "My curricula" list (null for an admin, who is never a curriculum mentor). The page has no single main action, so
     * every link is secondary. Coaches and admins also get the "Waiting for verification" list (waitingForVerification()).
     *
     * @return array{stats: array<string, array{label: string, value: int, url: ?string, link_label: ?string}>, waitingForVerification: list<array{participant_name: string, program_name: string, week_label: string, latest_submitted_at: string, latest_submitted_label: string, review_url: string}>, myPrograms: ?list<array{title: string, date_range: string, status_label: string, status_tone: string, participant_count_label: string, participants_url: string}>}
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
            'waitingForVerification' => $this->waitingForVerification($viewer),
            'myPrograms' => $viewer->hasAnyRole(UserRole::Coach) ? $this->myPrograms($viewer) : null,
        ];
    }

    /**
     * The viewer's current mentees (every participant for an admin) who handed in work for the week whose incomplete
     * review locks their later weeks since that review (CoachReportService::awaitingVerification()), the longest
     * waiting first, one row per curriculum membership: the participant, the curriculum's name, the week, the time of
     * the latest such work and the week's Gate review.
     *
     * @return list<array{participant_name: string, program_name: string, week_label: string, latest_submitted_at: string, latest_submitted_label: string, review_url: string}>
     */
    private function waitingForVerification(User $viewer): array
    {
        return array_map(function (array $row): array {
            ['membership' => $membership, 'review' => $review, 'latest_submitted_at' => $latestSubmittedAt] = $row;

            return [
                'participant_name' => $membership->participant->name,
                'program_name' => $membership->program->name,
                'week_label' => "Week {$review->gate->week->week_number}",
                'latest_submitted_at' => $latestSubmittedAt->toIso8601String(),
                'latest_submitted_label' => $latestSubmittedAt->format('M j, Y · g:i A'),
                'review_url' => route('training.coach.gates.show', [$membership->program, $review->gate->week, $membership->participant, $review->gate]),
            ];
        }, $this->reports->awaitingVerification($viewer));
    }

    /**
     * The curricula the coach is a mentor of, the latest first week start first (unscheduled curricula last), then by
     * name, each with its dates, status, how many of its participants the coach currently mentors there ("2
     * participants assigned to you") and the participants list filtered to it. One query.
     *
     * @return list<array{title: string, date_range: string, status_label: string, status_tone: string, participant_count_label: string, participants_url: string}>
     */
    private function myPrograms(User $coach): array
    {
        return $coach->mentoredPrograms()
            ->select(['programs.id', 'programs.name'])
            ->withScheduleBounds()
            ->withCount(['mentorAssignments as my_participant_count' => fn (Builder $assignments): Builder => $assignments
                ->current()
                ->where('mentor_id', $coach->id)])
            ->orderByDesc('schedule_starts_on')
            ->orderBy('programs.name')
            ->orderBy('programs.id')
            ->get()
            ->map(function (Program $program): array {
                $status = $program->status();

                return [
                    'title' => $program->name,
                    'date_range' => $program->dateRangeLabel(),
                    'status_label' => $status->label(),
                    'status_tone' => $status->tone(),
                    'participant_count_label' => $program->my_participant_count.' '.Str::plural('participant', $program->my_participant_count).' assigned to you',
                    'participants_url' => route('coach.participants', ['program' => $program->id]),
                ];
            })
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
