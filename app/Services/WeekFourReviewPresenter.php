<?php

namespace App\Services;

use App\Models\Activity;

class WeekFourReviewPresenter
{
    /** @return array<string, mixed>|null */
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
            2 => ['type' => 'interview', 'partner' => $values['partner_snapshot']['display_name'] ?? $values['partner_name'] ?? 'Partner recorded in submission', 'partner_email' => $values['partner_snapshot']['email'] ?? null, 'partner_path' => $values['partner_snapshot']['training_path'] ?? null, 'date' => $values['interview_date'] ?? null, 'mentor_schedule' => $values['practice_interview_snapshot']['mentor_schedule'] ?? null, 'questions' => $this->questions($values, $includeOfficialQuestionnaire), 'official_questions' => $includeOfficialQuestionnaire ? config('training.week_four_interview_questions', []) : []],
            3 => ['type' => 'assessment', 'sections' => [
                ['title' => 'Strict-mode Interview', 'fields' => $this->fields($values, ['strict_interview_transcript' => 'Transcript'])],
                ['title' => 'Assessment Tracker', 'fields' => $this->fields($values, ['assessment_tracker_result' => 'Tracker Result', 'assessment_level' => 'Level', 'assessment_confidence' => 'Confidence', 'updated_level_confidence' => 'Legacy Level / Confidence Notes'])],
            ]],
            default => null,
        };
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

            return ['forward' => collect($snapshot['questions_for_partner'] ?? [])->map(function ($question, $number) use ($snapshot): array {
                return ['number' => (int) $number, 'title' => 'Question by participant', 'prompt' => $question, 'answer' => $snapshot['partner_answers'][$number] ?? null, 'feedback' => $snapshot['feedback_given'][$number]['content'] ?? null];
            })->values()->all(), 'reverse' => collect($snapshot['partner_questions'] ?? [])->map(function ($question, $number) use ($snapshot): array {
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
