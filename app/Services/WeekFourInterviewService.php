<?php

namespace App\Services;

use App\Enums\InterviewPairStatus;
use App\Enums\MentorInterviewScheduleStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Models\WeekFourPartnerFeedback;
use App\Models\WeekFourPeerInterviewWaiver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

class WeekFourInterviewService
{
    /** @return array<int, array<string, mixed>> */
    public function questionnaire(): array
    {
        return config('training.week_four_interview_questions', []);
    }

    /** @return array<int, int> */
    public function practiceSlots(): array
    {
        return range(1, 15);
    }

    public function isLegacyAnswers(array $values): bool
    {
        return filled($values['question_1_answer'] ?? null)
            && blank($values['about_you'] ?? null)
            && blank($values['question_1_usage'] ?? null);
    }

    public function pairFor(User $participant): ?WeekFourInterviewPair
    {
        return WeekFourInterviewPair::forParticipant($participant->id);
    }

    /** @return Collection<int, WeekFourInterviewPair> */
    public function pairsFor(User $participant): Collection
    {
        return WeekFourInterviewPair::forParticipantMany($participant->id);
    }

    /**
     * The Week 4 peer mock interview activity (Activity 2), or null when it is not configured.
     */
    public function peerInterviewActivity(): ?Activity
    {
        return $this->peerInterviewActivityQuery()->first();
    }

    /**
     * The Week 4 peer mock interview activity (Activity 2); a missing activity is a 404.
     */
    public function peerInterviewActivityOrFail(): Activity
    {
        return $this->peerInterviewActivityQuery()->firstOrFail();
    }

    /** @return Collection<int, WeekFourInterviewPair> */
    public function activePairs(): Collection
    {
        return WeekFourInterviewPair::query()->where('status', InterviewPairStatus::Active)->with(['participantOne.assignedCoaches', 'participantTwo.assignedCoaches'])->get();
    }

    /**
     * Active participants that can be paired, with their first assigned mentor appended to the name for the picker.
     *
     * @return Collection<int, User>
     */
    public function pairingCandidates(): Collection
    {
        $participants = User::query()->where('role', 'participant')->where('is_active', true)->with('assignedCoaches')->orderBy('name')->get(['id', 'name', 'email']);
        $participants->each(function (User $participant): void {
            $participant->name = $participant->name.' — Mentor: '.($participant->assignedCoaches->first()?->name ?? 'Not assigned');
        });

        return $participants;
    }

    public function waiverFor(User $participant, Activity $activity): ?WeekFourPeerInterviewWaiver
    {
        return WeekFourPeerInterviewWaiver::query()
            ->where('participant_id', $participant->id)
            ->where('activity_id', $activity->id)
            ->whereNull('revoked_at')
            ->with('authorizedBy')
            ->first();
    }

    public function peerPrerequisiteSatisfied(User $participant, Activity $activity): bool
    {
        if ($this->waiverFor($participant, $activity)) {
            return true;
        }

        return $this->pairsFor($participant)->contains(fn (WeekFourInterviewPair $pair): bool => $this->pairCompletedFor($participant, $pair));
    }

    public function pairCompletedFor(User $participant, WeekFourInterviewPair $pair): bool
    {
        $partner = $pair->partnerFor($participant->id);
        if (! $partner) {
            return false;
        }

        $state = $this->participantPracticeState($participant, $partner);
        $feedback = $this->feedbackCountsForPair($participant, $pair);

        return count(array_filter($state['own_questions'], fn (mixed $value): bool => filled($value))) >= 15
            && count(array_filter($state['answers_to_partner'], fn (mixed $value): bool => filled($value))) >= 15
            && $feedback['given'] >= 2
            && $feedback['received'] >= 2;
    }

    public function waive(User $authorizer, User $participant, Activity $activity, ?string $reason = null): WeekFourPeerInterviewWaiver
    {
        abort_unless($authorizer->canManageParticipant($participant), 403);

        return WeekFourPeerInterviewWaiver::query()->updateOrCreate(
            ['participant_id' => $participant->id, 'activity_id' => $activity->id],
            ['authorized_by_id' => $authorizer->id, 'reason' => $reason, 'waived_at' => now(), 'revoked_at' => null]
        );
    }

    public function revokeWaiver(User $authorizer, User $participant, Activity $activity): void
    {
        abort_unless($authorizer->canManageParticipant($participant), 403);
        $scheduleExists = WeekFourMentorInterviewSchedule::query()->where('participant_id', $participant->id)->where('activity_id', $activity->id)->where('status', MentorInterviewScheduleStatus::Scheduled)->exists();
        $submittedExists = ActivitySubmission::query()->where('user_id', $participant->id)->where('activity_id', $activity->id)->whereIn('status', ['submitted', 'under_review', 'approved', 'completed'])->exists();
        if ($scheduleExists || $submittedExists) {
            throw ValidationException::withMessages(['waiver' => 'This waiver cannot be reversed after the participant has relied on it.']);
        }
        WeekFourPeerInterviewWaiver::query()->where('participant_id', $participant->id)->where('activity_id', $activity->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function assign(User $assigner, User $first, User $second): WeekFourInterviewPair
    {
        if ($first->role !== 'participant' || $second->role !== 'participant' || ! $first->is_active || ! $second->is_active || $first->is($second)) {
            throw ValidationException::withMessages(['partner' => 'Choose two different active participants.']);
        }
        if (! $assigner->canManageParticipant($first) || ! $assigner->canManageParticipant($second)) {
            throw ValidationException::withMessages(['partner' => 'You are not authorized to pair both participants.']);
        }
        $one = min($first->id, $second->id);
        $two = max($first->id, $second->id);

        return WeekFourInterviewPair::query()->updateOrCreate(['participant_one_id' => $one, 'participant_two_id' => $two], ['assigned_by_id' => $assigner->id, 'status' => InterviewPairStatus::Active]);
    }

    public function unpair(User $actor, WeekFourInterviewPair $pair): void
    {
        abort_unless($actor->canManageParticipant($pair->participantOne) && $actor->canManageParticipant($pair->participantTwo), 403);
        $hasHistory = $pair->feedback()->exists() || ActivitySubmission::query()->whereIn('user_id', [$pair->participant_one_id, $pair->participant_two_id])->whereHas('activity', fn (Builder $query): Builder => $query->where('position', 2)->whereHas('week', fn (Builder $week): Builder => $week->where('week_number', 4)))->whereIn('status', ['submitted', 'under_review', 'approved', 'completed'])->exists();
        $pair->update(['status' => $hasHistory ? InterviewPairStatus::Closed : InterviewPairStatus::Inactive]);
    }

    public function saveFeedback(User $author, int $question, string $content, ?int $pairId = null): WeekFourPartnerFeedback
    {
        $pair = $pairId
            ? WeekFourInterviewPair::query()->whereKey($pairId)->where('status', InterviewPairStatus::Active)->where(fn (Builder $query): Builder => $query->where('participant_one_id', $author->id)->orWhere('participant_two_id', $author->id))->first()
            : $this->pairFor($author);
        if (! $pair || blank(trim($content)) || $question < 1 || $question > 15) {
            throw ValidationException::withMessages(['feedback' => 'You must be paired and provide feedback for a valid interview question.']);
        }
        $recipient = $pair->partnerFor($author->id);
        abort_unless($recipient, 403);

        $authorVersion = $this->currentVersion($author);
        $recipientVersion = $this->currentVersion($recipient);
        $questions = $authorVersion?->field_values['practice_questions'] ?? [];
        $answers = $recipientVersion?->field_values['practice_answers'] ?? [];
        if (filled($questions) && blank($questions[$question] ?? null)) {
            throw ValidationException::withMessages(['feedback' => 'Feedback can only be added to a question you created.']);
        }
        if (filled($questions) && blank($answers[$question] ?? null)) {
            throw ValidationException::withMessages(['feedback' => 'Wait for your partner to answer this practice question before giving feedback.']);
        }

        return WeekFourPartnerFeedback::query()->updateOrCreate(['pair_id' => $pair->id, 'author_id' => $author->id, 'recipient_id' => $recipient->id, 'question_number' => $question], ['content' => trim($content)]);
    }

    public function currentVersion(User $participant): ?SubmissionVersion
    {
        $activity = Activity::query()->where('position', 2)->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 4))->first();
        if (! $activity) {
            return null;
        }

        return ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $participant->id)->latest('attempt_number')->with(['versions' => fn (Relation $query): Relation => $query->orderByDesc('version_number')])->first()?->versions->first();
    }

    /** @return array<string, mixed> */
    public function participantPracticeState(User $participant, ?User $partner): array
    {
        $ownVersion = $this->currentVersion($participant);
        $partnerVersion = $partner ? $this->currentVersion($partner) : null;
        $ownValues = $ownVersion?->field_values ?? [];
        $partnerValues = $partnerVersion?->field_values ?? [];

        return [
            'own_questions' => array_replace(array_fill_keys($this->practiceSlots(), ''), $ownValues['practice_questions'] ?? []),
            'partner_questions' => array_replace(array_fill_keys($this->practiceSlots(), ''), $partnerValues['practice_questions'] ?? []),
            'answers_to_partner' => array_replace(array_fill_keys($this->practiceSlots(), ''), $ownValues['practice_answers'] ?? []),
            'partner_answers' => array_replace(array_fill_keys($this->practiceSlots(), ''), $partnerValues['practice_answers'] ?? []),
            'own_version_status' => $ownVersion?->status,
            'partner_version_status' => $partnerVersion?->status,
        ];
    }

    public function feedbackCounts(User $participant): array
    {
        $pair = $this->pairFor($participant);
        if (! $pair) {
            return ['given' => 0, 'received' => 0];
        }

        return $this->feedbackCountsForPair($participant, $pair);
    }

    public function feedbackCountsForPair(User $participant, WeekFourInterviewPair $pair): array
    {
        return ['given' => $pair->feedback()->where('author_id', $participant->id)->whereNotNull('content')->count(), 'received' => $pair->feedback()->where('recipient_id', $participant->id)->whereNotNull('content')->count()];
    }

    public function snapshot(User $participant): array
    {
        $pair = $this->pairFor($participant);
        $partner = $pair?->partnerFor($participant->id);
        $state = $this->participantPracticeState($participant, $partner);

        $activity = Activity::query()->where('position', 2)->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 4))->first();
        $schedule = $activity ? WeekFourMentorInterviewSchedule::query()->where('participant_id', $participant->id)->where('activity_id', $activity->id)->with('mentor')->first() : null;

        return ['partner' => $partner ? ['display_name' => $partner->name, 'email' => $partner->email, 'training_path' => $partner->training_path?->value] : null, 'questions_for_partner' => $state['own_questions'], 'partner_questions' => $state['partner_questions'], 'answers_to_partner' => $state['answers_to_partner'], 'partner_answers' => $state['partner_answers'], 'feedback_given' => $pair?->feedback()->where('author_id', $participant->id)->get()->mapWithKeys(fn (WeekFourPartnerFeedback $item): array => [(string) $item->question_number => ['content' => $item->content, 'author_name' => $participant->name, 'recipient_name' => $partner?->name]])->all() ?? [], 'feedback_received' => $pair?->feedback()->where('recipient_id', $participant->id)->get()->mapWithKeys(fn (WeekFourPartnerFeedback $item): array => [(string) $item->question_number => ['content' => $item->content, 'author_name' => $partner?->name, 'recipient_name' => $participant->name]])->all() ?? [], 'mentor_schedule' => $schedule ? ['mentor_name' => $schedule->mentor?->name, 'mentor_email' => $schedule->mentor?->email, 'starts_at' => $schedule->starts_at?->toIso8601String(), 'ends_at' => $schedule->ends_at?->toIso8601String(), 'timezone' => $schedule->timezone, 'duration_minutes' => $schedule->duration_minutes, 'status' => $schedule->status->value, 'google_event_id' => $schedule->google_event_id] : null];
    }

    /** @return Builder<Activity> */
    private function peerInterviewActivityQuery(): Builder
    {
        return Activity::query()->where('position', 2)->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 4));
    }
}
