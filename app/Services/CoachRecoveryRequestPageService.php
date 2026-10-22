<?php

namespace App\Services;

use App\Enums\RecoveryRequestStatus;
use App\Enums\UserRole;
use App\Models\ActivityRecoveryRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachRecoveryRequestPageService
{
    private const REQUESTS_PER_PAGE = 25;

    /**
     * Everything the coach recovery requests page renders: the pending requests of the participants the viewer
     * manages (admins: all), newest first, each with the form that approves a retry or keeps the attempt failed. The
     * page has no single main action, so every button is secondary.
     *
     * @return array{items: LengthAwarePaginator<int, array{week_number: int, activity_title: string, participant_name: string, attempt_number: int, message: string, decide_url: string}>, decisions: list<array{value: string, label: string}>}
     */
    public function indexData(User $viewer): array
    {
        return [
            'items' => ActivityRecoveryRequest::query()
                ->with(['participant', 'submission.activity.week'])
                ->where('status', RecoveryRequestStatus::Pending)
                ->when(! $viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->whereHas('participant.assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($viewer->id)))
                ->latest()
                ->paginate(self::REQUESTS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ActivityRecoveryRequest $recoveryRequest): array => $this->requestRow($recoveryRequest)),
            'decisions' => array_map(fn (RecoveryRequestStatus $decision): array => [
                'value' => $decision->value,
                'label' => match ($decision) {
                    RecoveryRequestStatus::Approved => 'Approve Retry',
                    RecoveryRequestStatus::Declined => 'Keep Failed',
                },
            ], RecoveryRequestStatus::decisions()),
        ];
    }

    /**
     * One pending request: the week and activity of the failed attempt, the participant, the attempt number, the
     * participant's reason (or a fallback) and the URL the decision form posts to.
     *
     * @return array{week_number: int, activity_title: string, participant_name: string, attempt_number: int, message: string, decide_url: string}
     */
    private function requestRow(ActivityRecoveryRequest $recoveryRequest): array
    {
        $submission = $recoveryRequest->submission;

        return [
            'week_number' => $submission->activity->week->week_number,
            'activity_title' => $submission->activity->title,
            'participant_name' => $recoveryRequest->participant->name,
            'attempt_number' => $submission->attempt_number,
            'message' => $recoveryRequest->message ?: 'No reason provided.',
            'decide_url' => route('coach.recovery.decide', $recoveryRequest),
        ];
    }
}
