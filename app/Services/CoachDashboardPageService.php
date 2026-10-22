<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Program;

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
     * Everything the coach dashboard renders: whether a program is active (the page shows a notice when none is), the
     * headline counts with their labels and, where a page lists that set, the link to it with its accessible text.
     * The page has no single main action, so every link is secondary.
     *
     * @return array{hasActiveProgram: bool, stats: array<string, array{label: string, value: int, url: ?string, link_label: ?string}>}
     */
    public function showData(): array
    {
        $activeProgram = Program::query()->where('is_active', true)->first();
        $links = $this->statLinks();
        $stats = [];

        foreach ($this->reports->dashboardStats($activeProgram) as $key => $value) {
            $stats[$key] = [
                'label' => self::STAT_LABELS[$key],
                'value' => $value,
                'url' => $links[$key]['url'] ?? null,
                'link_label' => $links[$key]['link_label'] ?? null,
            ];
        }

        return [
            'hasActiveProgram' => $activeProgram !== null,
            'stats' => $stats,
        ];
    }

    /**
     * The page listing each stat's set, keyed by stat: all participants, the review queue (submissions awaiting review
     * are listed there with those needing revision), the queue filtered to needs revision and the progress report's
     * at-risk table. No page lists the completed participants, so that stat has no link.
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
