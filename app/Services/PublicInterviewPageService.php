<?php

namespace App\Services;

use App\Models\InterviewScheduler;
use Illuminate\Support\Collection;

class PublicInterviewPageService
{
    /**
     * The interview type options of the public interviewer registration form, in display order.
     *
     * @var array<string, string>
     */
    private const INTERVIEW_TYPE_LABELS = [
        InterviewScheduler::MOCK => 'Mock Interview',
        InterviewScheduler::FINAL_REASSESSMENT => 'Final Re-assessment Interview',
        InterviewScheduler::BOTH => 'Both',
    ];

    public function __construct(private readonly InterviewBookingLinkService $interviewBookingLinks) {}

    /**
     * The public interview scheduling page: the branding (the configured logo, or the public title as the fallback),
     * the Mock Interview and Final Re-assessment tabs, and the approved active interviewers of each type in display
     * order with their booking link (an interviewer of both types is listed in both tabs, and a missing type-specific
     * link falls back to the shared one).
     *
     * @return array{publicTitle: string, logoUrl: ?string, registerUrl: string, tabs: list<array{key: string, label: string}>, selectedTab: string, mockInterviewers: list<array{fullName: string, bookingUrl: ?string}>, finalInterviewers: list<array{fullName: string, bookingUrl: ?string}>}
     */
    public function indexData(): array
    {
        $interviewers = InterviewScheduler::query()
            ->where('is_active', true)
            ->where('approval_status', InterviewScheduler::APPROVED)
            ->whereIn('interview_type', [InterviewScheduler::MOCK, InterviewScheduler::FINAL_REASSESSMENT, InterviewScheduler::BOTH])
            ->orderBy('display_order')
            ->orderBy('full_name')
            ->get();

        return [
            'publicTitle' => $this->interviewBookingLinks->publicTitle(),
            'logoUrl' => filled($this->interviewBookingLinks->logoPath()) ? route('interviews.logo') : null,
            'registerUrl' => route('interviews.register'),
            'tabs' => [
                ['key' => 'mock', 'label' => 'Mock Interview'],
                ['key' => 'final', 'label' => 'Final Re-assessment Interview'],
            ],
            'selectedTab' => 'mock',
            'mockInterviewers' => $this->interviewerRows($interviewers, InterviewScheduler::MOCK),
            'finalInterviewers' => $this->interviewerRows($interviewers, InterviewScheduler::FINAL_REASSESSMENT),
        ];
    }

    /**
     * The public interviewer registration page: the public title, the interview type options and the form URLs.
     *
     * @return array{publicTitle: string, interviewTypes: list<array{value: string, label: string}>, storeUrl: string, schedulingUrl: string}
     */
    public function registerData(): array
    {
        $interviewTypes = [];

        foreach (self::INTERVIEW_TYPE_LABELS as $value => $label) {
            $interviewTypes[] = ['value' => $value, 'label' => $label];
        }

        return [
            'publicTitle' => $this->interviewBookingLinks->publicTitle(),
            'interviewTypes' => $interviewTypes,
            'storeUrl' => route('interviews.register.store'),
            'schedulingUrl' => route('interviews.index'),
        ];
    }

    /**
     * The interviewers offering the given interview type (or both types), each with its booking link for that type.
     *
     * @param  Collection<int, InterviewScheduler>  $interviewers
     * @return list<array{fullName: string, bookingUrl: ?string}>
     */
    private function interviewerRows(Collection $interviewers, string $interviewType): array
    {
        return $interviewers
            ->filter(fn (InterviewScheduler $interviewer): bool => in_array($interviewer->interview_type, [$interviewType, InterviewScheduler::BOTH], true))
            ->map(fn (InterviewScheduler $interviewer): array => [
                'fullName' => $interviewer->full_name,
                'bookingUrl' => $this->interviewBookingLinks->bookingUrl($interviewer, $interviewType),
            ])
            ->values()
            ->all();
    }
}
