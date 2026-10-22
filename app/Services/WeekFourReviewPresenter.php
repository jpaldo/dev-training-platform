<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Models\Activity;

class WeekFourReviewPresenter
{
    /**
     * The Week 4 review block of a submission, or null outside Week 4. With `$includeOfficialQuestionnaire` (the coach
     * review) a Week 4 Activity 2 question snapshot shows each question's title and prompt; otherwise it shows
     * "Practice question" without a prompt.
     *
     * @return array<string, mixed>|null
     */
    public function present(Activity $activity, array $values, bool $includeOfficialQuestionnaire = false): ?array
    {
        if ($activity->week?->week_number !== 4) {
            return null;
        }

        return match ((int) $activity->position) {
            1 => ['type' => 'asset', 'sections' => [
                ['title' => 'Reusable AI Asset', 'fields' => $this->fields($values, ['asset_name' => 'Asset', 'asset_type' => 'Type', 'asset_purpose' => 'Purpose', 'asset_content' => 'Asset Content'])],
                ['title' => 'Original Use', 'fields' => $this->fields($values, ['original_task' => 'Task / Scenario', 'original_use' => 'How It Was Used'])],
                ['title' => 'Second Real-Task Validation', 'fields' => $this->fields($values, ['second_task' => 'Task / Scenario', 'reuse_method' => 'How It Was Reused', 'reuse_result' => 'Result', 'reuse_modified' => 'Changes Made', 'reuse_changes' => 'What Changed and Why'])],
                ['title' => 'Reflection', 'fields' => $this->fields($values, ['reusability_learning' => 'What Made It Reusable', 'reusability_improvement' => 'What I Would Improve'])],
            ]],
            2 => $this->interview($values, $includeOfficialQuestionnaire),
            3 => ['type' => 'assessment', 'sections' => [
                ['title' => 'Strict-mode Interview', 'fields' => $this->fields($values, ['strict_interview_transcript' => 'Transcript'])],
                ['title' => 'Assessment Tracker', 'fields' => $this->fields($values, ['assessment_tracker_result' => 'Tracker Result', 'assessment_level' => 'Level', 'assessment_confidence' => 'Confidence', 'updated_level_confidence' => 'Legacy Level / Confidence Notes'])],
            ]],
            default => null,
        };
    }

    /**
     * The Week 4 Activity 2 mock interview: the partner (name, email and training path label), the interview date, the
     * mentor mock interview snapshot and the question groups. A peer practice snapshot gives two groups (questions asked
     * by the participant, then by the partner); older submissions give one group of historical responses.
     *
     * @param  array<string, mixed>  $values
     * @return array{type: string, partner: mixed, partner_email: mixed, partner_path_label: ?string, date: mixed, mentor_schedule: mixed, question_groups: list<array{label: string, answer_label: string, feedback_label: string, questions: array<int|string, mixed>}>}
     */
    private function interview(array $values, bool $includeOfficialQuestionnaire): array
    {
        $partnerPath = $values['partner_snapshot']['training_path'] ?? null;
        $questions = $this->questions($values, $includeOfficialQuestionnaire);

        return [
            'type' => 'interview',
            'partner' => $values['partner_snapshot']['display_name'] ?? $values['partner_name'] ?? 'Partner recorded in submission',
            'partner_email' => $values['partner_snapshot']['email'] ?? null,
            'partner_path_label' => is_string($partnerPath) ? TrainingPath::tryFrom($partnerPath)?->label() : null,
            'date' => $values['interview_date'] ?? null,
            'mentor_schedule' => $values['practice_interview_snapshot']['mentor_schedule'] ?? null,
            'question_groups' => isset($questions['forward']) ? [
                ['label' => 'Questions Asked by Participant', 'answer_label' => 'Partner Answer', 'feedback_label' => 'Participant Feedback', 'questions' => $questions['forward']],
                ['label' => 'Questions Asked by Partner', 'answer_label' => 'Participant Answer', 'feedback_label' => 'Partner Feedback', 'questions' => $questions['reverse']],
            ] : [
                ['label' => 'Historical Interview Responses', 'answer_label' => 'Participant Answer', 'feedback_label' => 'Partner Feedback', 'questions' => $questions],
            ],
        ];
    }

    /** @param array<string, mixed> $values @param array<string, string> $labels @return array<int, array{label:string,value:mixed}> */
    private function fields(array $values, array $labels): array
    {
        return collect($labels)->map(fn (string $label, string $key): ?array => blank($values[$key] ?? null) ? null : ['label' => $label, 'value' => $values[$key]])->filter()->values()->all();
    }

    /** @return array<int|string, mixed> */
    private function questions(array $values, bool $includeOfficialQuestionnaire): array
    {
        if (filled($values['practice_interview_snapshot'] ?? null)) {
            $snapshot = $values['practice_interview_snapshot'];

            return ['forward' => collect($snapshot['questions_for_partner'] ?? [])->map(function (mixed $question, int|string $number) use ($snapshot): array {
                return ['number' => (int) $number, 'title' => 'Question by participant', 'prompt' => $question, 'answer' => $snapshot['partner_answers'][$number] ?? null, 'feedback' => $snapshot['feedback_given'][$number]['content'] ?? null];
            })->values()->all(), 'reverse' => collect($snapshot['partner_questions'] ?? [])->map(function (mixed $question, int|string $number) use ($snapshot): array {
                return ['number' => (int) $number, 'title' => 'Question by partner', 'prompt' => $question, 'answer' => $snapshot['answers_to_partner'][$number] ?? null, 'feedback' => $snapshot['feedback_received'][$number]['content'] ?? null];
            })->values()->all()];
        }
        $received = $values['mutual_feedback_snapshot']['received'] ?? [];
        $snapshot = $values['interview_question_snapshot'] ?? [];
        if (filled($snapshot) && is_array($snapshot)) {
            return collect($snapshot)->map(function (array $question, int|string $number) use ($values, $received): array {
                $feedback = $received[$number] ?? $received[(string) $number] ?? null;

                return ['number' => (int) $number, 'title' => $includeOfficialQuestionnaire ? ($question['title'] ?? 'Interview question') : 'Practice question', 'prompt' => $includeOfficialQuestionnaire ? ($question['prompt'] ?? '') : '', 'answer' => $values[$question['key'] ?? ''] ?? null, 'feedback' => is_array($feedback) ? ($feedback['content'] ?? null) : $feedback];
            })->values()->all();
        }
        $questions = config('training.week_four_interview_questions', []);
        if (filled($values['about_you'] ?? null)) {
            return collect($questions)->map(function (array $question, int|string $number) use ($values, $received): array {
                $feedback = $received[$number] ?? $received[(string) $number] ?? null;

                return ['number' => (int) $number, 'title' => 'Historical practice question', 'prompt' => '', 'answer' => $values[$question['key']] ?? null, 'feedback' => is_array($feedback) ? ($feedback['content'] ?? null) : $feedback];
            })->all();
        }

        return collect([1, 2, 3])->map(fn (int $number): array => ['number' => $number, 'title' => 'Legacy interview question', 'prompt' => '', 'answer' => $values['question_'.$number.'_answer'] ?? null, 'feedback' => $received[$number] ?? $values['question_'.$number.'_partner_feedback'] ?? null])->all();
    }
}
