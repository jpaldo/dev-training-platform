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

    public function __construct(private readonly CoachSessionFilterService $sessionFilter) {}

    /**
     * Everything the coach recovery requests page renders: the training sessions the viewer may filter by, whether a
     * session is selected, and the pending requests whose failed attempt the viewer may decide (every one for an
     * admin, otherwise those whose participant the viewer currently mentors in the attempt's session) in the selected
     * session, newest first, each with its training session and the form that approves a retry or keeps the attempt
     * failed. The page has no single main action, so every button is secondary.
     *
     * @param  array{session?: int|string|null}  $filters
     * @return array{sessionOptions: list<array{value: string, label: string, is_selected: bool}>, isFiltered: bool, emptyMessage: string, items: LengthAwarePaginator<int, array{week_number: int, activity_title: string, participant_name: string, session_title: string, session_dates: string, attempt_number: int, message: string, decide_url: string}>, decisions: list<array{value: string, label: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        $trainingSessionId = $filters['session'] ?? null;
        $isFiltered = filled($trainingSessionId);

        return [
            'sessionOptions' => $this->sessionFilter->options($viewer, $trainingSessionId),
            'isFiltered' => $isFiltered,
            'emptyMessage' => $isFiltered ? 'No pending recovery requests in this training session.' : 'No pending recovery requests.',
            'items' => ActivityRecoveryRequest::query()
                ->with(['participant', 'submission.activity.week', 'submission.trainingSession:id,title,starts_on,ends_on'])
                ->where('status', RecoveryRequestStatus::Pending)
                ->whereHas('submission', fn (Builder $submissions): Builder => $submissions
                    ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
                    ->when($trainingSessionId, fn (Builder $query, int|string $sessionId): Builder => $query->where('training_session_id', $sessionId)))
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
     * One pending request: the week and activity of the failed attempt, the participant, the attempt's training
     * session and its dates, the attempt number, the participant's reason (or a fallback) and the URL the decision
     * form posts to.
     *
     * @return array{week_number: int, activity_title: string, participant_name: string, session_title: string, session_dates: string, attempt_number: int, message: string, decide_url: string}
     */
    private function requestRow(ActivityRecoveryRequest $recoveryRequest): array
    {
        $submission = $recoveryRequest->submission;

        return [
            'week_number' => $submission->activity->week->week_number,
            'activity_title' => $submission->activity->title,
            'participant_name' => $recoveryRequest->participant->name,
            'session_title' => $submission->trainingSession->title,
            'session_dates' => $submission->trainingSession->dateRangeLabel(),
            'attempt_number' => $submission->attempt_number,
            'message' => $recoveryRequest->message ?: 'No reason provided.',
            'decide_url' => route('coach.recovery.decide', $recoveryRequest),
        ];
    }
}
