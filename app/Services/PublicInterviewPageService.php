<?php

namespace App\Services;

use App\Enums\InterviewerApprovalStatus;
use App\Enums\InterviewType;
use App\Models\InterviewScheduler;
use Illuminate\Support\Collection;

class PublicInterviewPageService
{
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
            ->where('approval_status', InterviewerApprovalStatus::Approved)
            ->whereIn('interview_type', InterviewType::cases())
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
            'mockInterviewers' => $this->interviewerRows($interviewers, InterviewType::Mock),
            'finalInterviewers' => $this->interviewerRows($interviewers, InterviewType::FinalReassessment),
        ];
    }

    /**
     * The public interviewer registration page: the public title, the interview type options (in case order) and
     * the form URLs.
     *
     * @return array{publicTitle: string, interviewTypes: list<array{value: string, label: string}>, storeUrl: string, schedulingUrl: string}
     */
    public function registerData(): array
    {
        return [
            'publicTitle' => $this->interviewBookingLinks->publicTitle(),
            'interviewTypes' => array_map(
                fn (InterviewType $interviewType): array => ['value' => $interviewType->value, 'label' => $interviewType->label()],
                InterviewType::cases(),
            ),
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
    private function interviewerRows(Collection $interviewers, InterviewType $interviewType): array
    {
        return $interviewers
            ->filter(fn (InterviewScheduler $interviewer): bool => $interviewer->interview_type->offers($interviewType))
            ->map(fn (InterviewScheduler $interviewer): array => [
                'fullName' => $interviewer->full_name,
                'bookingUrl' => $this->interviewBookingLinks->bookingUrl($interviewer, $interviewType),
            ])
            ->values()
            ->all();
    }
}
