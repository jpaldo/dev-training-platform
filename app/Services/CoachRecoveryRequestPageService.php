<?php

namespace App\Services;

use App\Enums\RecoveryRequestStatus;
use App\Enums\UserRole;
use App\Models\ActivityRecoveryRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachRecoveryRequestPageService
{
    private const REQUESTS_PER_PAGE = 25;

    public function __construct(private readonly CoachProgramFilterService $programFilter) {}

    /**
     * Everything the coach recovery requests page renders: the curricula the viewer may filter by, whether a
     * curriculum is selected, and the pending requests whose failed attempt the viewer may decide (every one for an
     * admin, otherwise those whose participant the viewer currently mentors in the attempt's curriculum) in the
     * selected curriculum, newest first, each with its curriculum (loaded with its schedule bounds, so its dates need
     * no query per row) and the form that approves a retry or keeps the attempt failed. The page has no single main
     * action, so every button is secondary.
     *
     * @param  array{program?: int|string|null}  $filters
     * @return array{programOptions: list<array{value: string, label: string, is_selected: bool}>, isFiltered: bool, emptyMessage: string, items: LengthAwarePaginator<int, array{week_number: int, activity_title: string, participant_name: string, program_name: string, program_dates: string, attempt_number: int, message: string, decide_url: string}>, decisions: list<array{value: string, label: string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        $programId = $filters['program'] ?? null;
        $isFiltered = filled($programId);

        return [
            'programOptions' => $this->programFilter->options($viewer, $programId),
            'isFiltered' => $isFiltered,
            'emptyMessage' => $isFiltered ? 'No pending recovery requests in this curriculum.' : 'No pending recovery requests.',
            'items' => ActivityRecoveryRequest::query()
                ->with([
                    'participant',
                    'submission.activity.week.program' => fn (Relation $programs): Relation => $programs->select(['id', 'name'])->withScheduleBounds(),
                ])
                ->where('status', RecoveryRequestStatus::Pending)
                ->whereHas('submission', fn (Builder $submissions): Builder => $submissions
                    ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $query): Builder => $query->currentlyMentoredBy($viewer))
                    ->when($programId, fn (Builder $query, int|string $programId): Builder => $query
                        ->whereHas('activity.week', fn (Builder $weeks): Builder => $weeks->where('program_id', $programId))))
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
     * One pending request: the week and activity of the failed attempt, the participant, the attempt's curriculum and
     * its dates, the attempt number, the participant's reason (or a fallback) and the URL the decision
     * form posts to.
     *
     * @return array{week_number: int, activity_title: string, participant_name: string, program_name: string, program_dates: string, attempt_number: int, message: string, decide_url: string}
     */
    private function requestRow(ActivityRecoveryRequest $recoveryRequest): array
    {
        $submission = $recoveryRequest->submission;

        return [
            'week_number' => $submission->activity->week->week_number,
            'activity_title' => $submission->activity->title,
            'participant_name' => $recoveryRequest->participant->name,
            'program_name' => $submission->activity->week->program->name,
            'program_dates' => $submission->activity->week->program->dateRangeLabel(),
            'attempt_number' => $submission->attempt_number,
            'message' => $recoveryRequest->message ?: 'No reason provided.',
            'decide_url' => route('coach.recovery.decide', $recoveryRequest),
        ];
    }
}
