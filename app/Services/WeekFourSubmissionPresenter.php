<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourPartnerFeedback;
use Illuminate\Support\Collection;

class WeekFourSubmissionPresenter
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly WeekFourInterviewService $interviews,
        private readonly MentorInterviewSchedulingService $mentorScheduling,
        private readonly SubmissionWorkflowService $workflow,
    ) {}

    /**
     * The Week 4 Activity 2 mock interview context: the partners with their Week 4 access, the partner feedback counts
     * and rows, the practice question rows, the peer interview progress, the mentor and open schedule, and the peer
     * interview waiver.
     *
     * @return array<string, mixed>
     */
    public function interviewData(User $participant, TrainingWeek $trainingWeek, Activity $activity, ?SubmissionVersion $version): array
    {
        $mentorInterview = [
            'mentorInterviewTimezone' => config('training.week_four_mentor_interview_timezone'),
            'mentorInterviewDurationMinutes' => config('training.week_four_mentor_interview_duration_minutes'),
        ];

        if ($trainingWeek->week_number !== 4 || $activity->position !== 2) {
            return [
                'interviewPartner' => null,
                'partnerRows' => [],
                'partnerFeedback' => null,
                'practiceRows' => [],
                'peerInterviewProgress' => [],
                'mentor' => null,
                'mentorSchedule' => null,
                ...$mentorInterview,
                'peerWaiver' => null,
                'peerPrerequisiteSatisfied' => true,
            ];
        }

        $interviewPairs = $this->interviews->pairsFor($participant);
        $interviewPair = $interviewPairs->first();
        $interviewPartner = $interviewPair?->partnerFor($participant->id);
        $interviewPartners = $interviewPairs->map(fn (WeekFourInterviewPair $pair): ?User => $pair->partnerFor($participant->id))->filter()->values();
        $weekFour = $trainingWeek->program->weeks()->where('week_number', 4)->first();
        $feedbackGiven = $interviewPair?->feedback()->where('author_id', $participant->id)->get()->keyBy('question_number') ?? collect();
        $feedbackReceived = $interviewPair?->feedback()->where('recipient_id', $participant->id)->get()->keyBy('question_number') ?? collect();
        $practiceState = $this->interviews->participantPracticeState($participant, $interviewPartner);

        return [
            'interviewPartner' => $interviewPartner,
            'partnerRows' => $interviewPartners->map(fn (User $partner): array => [
                'partner' => $partner,
                'week_four_unlocked' => $this->weekFourStatus($partner, $weekFour) === 'unlocked',
            ])->all(),
            'partnerFeedback' => $this->partnerFeedback($practiceState, $feedbackGiven, $feedbackReceived),
            'practiceRows' => $this->practiceRows($practiceState),
            'peerInterviewProgress' => $this->peerInterviewProgress($interviewPartner, $version, $practiceState, $feedbackGiven, $feedbackReceived),
            'mentor' => $this->mentorScheduling->assignedMentor($participant),
            'mentorSchedule' => $this->mentorScheduling->openScheduleFor($participant, $activity),
            ...$mentorInterview,
            'peerWaiver' => $this->interviews->waiverFor($participant, $activity),
            'peerPrerequisiteSatisfied' => $this->interviews->peerPrerequisiteSatisfied($participant, $activity),
        ];
    }

    /**
     * The Week 4 activity guide: the activity heading and its progress checklist, read from the version's answers. The
     * "Evidence" step is complete when the version has evidence and every required evidence requirement is met, and
     * "Ready to submit" when the version meets the activity's submission requirements (the checks of submit()).
     * Activity 2 has no checklist here: its Peer Interview Progress is the one checklist on that page.
     *
     * @return array{title: string, progress: list<array{label: string, complete: bool}>}
     */
    public function guide(User $participant, Activity $activity, ?SubmissionVersion $version): array
    {
        $values = $version?->field_values ?? [];
        $isAnswered = fn (string $key): bool => filled($values[$key] ?? null);
        $hasRequiredEvidence = fn (): bool => $version !== null && $version->evidence->isNotEmpty() && $this->workflow->hasRequiredEvidence($participant, $activity, $version);
        $isReadyToSubmit = fn (): bool => $version !== null && $this->workflow->meetsSubmissionRequirements($participant, $activity, $version);

        [$title, $steps] = match ($activity->position) {
            1 => ['Build & Validate a Reusable AI Asset', [
                'Asset' => $isAnswered('asset_name'),
                'Original use' => $isAnswered('original_task'),
                'Second task' => $isAnswered('second_task'),
                'Reflection' => $isAnswered('reusability_learning'),
                'Evidence' => $hasRequiredEvidence(),
                'Ready to submit' => $isReadyToSubmit(),
            ]],
            2 => ['Mock Level 3 Interview', []],
            default => ['Strict-mode Leveling Assessment', [
                'Transcript' => $isAnswered('strict_interview_transcript'),
                'Tracker result' => $isAnswered('assessment_tracker_result'),
                'Level' => $isAnswered('assessment_level'),
                'Confidence' => $isAnswered('assessment_confidence'),
                'Evidence' => $hasRequiredEvidence(),
                'Ready to submit' => $isReadyToSubmit(),
            ]],
        };

        return [
            'title' => $title,
            'progress' => array_map(fn (string $label, bool $complete): array => ['label' => $label, 'complete' => $complete], array_keys($steps), array_values($steps)),
        ];
    }

    /**
     * The partner feedback of the Week 4 Activity 2 guide: how many pieces of feedback were given and received, one row
     * per own question the partner has answered (with my saved feedback), and one row per question the partner has
     * prepared for me (with the partner's feedback on my answer). Without a partner both lists are empty.
     *
     * @param  array<string, mixed>  $practiceState
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackGiven
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackReceived
     * @return array{given_count: int, received_count: int, given_rows: list<array{number: int, question: mixed, answer: mixed, feedback: ?string}>, received_rows: list<array{number: int, question: mixed, feedback: ?string}>}
     */
    private function partnerFeedback(array $practiceState, Collection $feedbackGiven, Collection $feedbackReceived): array
    {
        $givenRows = [];
        $receivedRows = [];

        foreach ($this->interviews->practiceSlots() as $number) {
            $question = $practiceState['own_questions'][$number] ?? '';
            $answer = $practiceState['partner_answers'][$number] ?? '';
            if (filled($question) && filled($answer)) {
                $givenRows[] = ['number' => $number, 'question' => $question, 'answer' => $answer, 'feedback' => $feedbackGiven->get($number)?->content];
            }

            $partnerQuestion = $practiceState['partner_questions'][$number] ?? '';
            if (filled($partnerQuestion)) {
                $receivedRows[] = ['number' => $number, 'question' => $partnerQuestion, 'feedback' => $feedbackReceived->get($number)?->content];
            }
        }

        return [
            'given_count' => $feedbackGiven->count(),
            'received_count' => $feedbackReceived->count(),
            'given_rows' => $givenRows,
            'received_rows' => $receivedRows,
        ];
    }

    /**
     * One row per practice question slot: the partner's question and my answer to it, my own question, the partner's
     * answer to it, and whether that answer locks my question.
     *
     * @param  array<string, mixed>  $practiceState
     * @return list<array{number: int, partner_question: mixed, answer: mixed, own_question: mixed, partner_answer: mixed, is_locked: bool}>
     */
    private function practiceRows(array $practiceState): array
    {
        return array_map(fn (int $number): array => [
            'number' => $number,
            'partner_question' => $practiceState['partner_questions'][$number] ?? '',
            'answer' => $practiceState['answers_to_partner'][$number] ?? '',
            'own_question' => $practiceState['own_questions'][$number] ?? '',
            'partner_answer' => $practiceState['partner_answers'][$number] ?? null,
            'is_locked' => filled($practiceState['partner_answers'][$number] ?? null),
        ], $this->interviews->practiceSlots());
    }

    /**
     * The peer interview checklist: partner assigned, About You, 15 own and 15 answered practice questions, and two
     * pieces of feedback given and received.
     *
     * @param  array<string, mixed>  $practiceState
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackGiven
     * @param  Collection<int, WeekFourPartnerFeedback>  $feedbackReceived
     * @return list<array{label: string, complete: bool}>
     */
    private function peerInterviewProgress(?User $interviewPartner, ?SubmissionVersion $version, array $practiceState, Collection $feedbackGiven, Collection $feedbackReceived): array
    {
        $isFilled = fn (mixed $value): bool => filled($value);
        $preparedCount = count(array_filter($practiceState['own_questions'] ?? [], $isFilled));
        $answeredCount = count(array_filter($practiceState['answers_to_partner'] ?? [], $isFilled));
        $feedbackGivenCount = $feedbackGiven->filter(fn (WeekFourPartnerFeedback $item): bool => filled($item->content))->count();
        $feedbackReceivedCount = $feedbackReceived->filter(fn (WeekFourPartnerFeedback $item): bool => filled($item->content))->count();

        return [
            ['label' => 'Partner assigned', 'complete' => $interviewPartner !== null],
            ['label' => 'About You', 'complete' => filled($version?->field_values['about_you'] ?? null)],
            ['label' => 'My practice questions: '.$preparedCount.' / 15', 'complete' => $preparedCount >= 15],
            ['label' => 'Partner questions answered: '.$answeredCount.' / 15', 'complete' => $answeredCount >= 15],
            ['label' => 'Feedback given: '.$feedbackGivenCount.' / 2 required', 'complete' => $feedbackGivenCount >= 2],
            ['label' => 'Feedback received: '.$feedbackReceivedCount.' / 2 required', 'complete' => $feedbackReceivedCount >= 2],
        ];
    }

    /**
     * Whether a mock interview partner has reached Week 4 ("unlocked") or not ("locked").
     */
    private function weekFourStatus(User $partner, ?TrainingWeek $weekFour): string
    {
        return $weekFour && ($this->progression->canAccessWeek($partner, $weekFour) || $this->progression->isWeekPassed($partner, $weekFour)) ? 'unlocked' : 'locked';
    }
}
