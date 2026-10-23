<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSecondReaderScoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request: an admin or the participant's current mentor in the
     * submission's session.
     */
    public function authorize(): bool
    {
        $submission = $this->route('submissionVersion')?->loadMissing(['submission.participant', 'submission.trainingSession'])->submission;

        return $submission !== null && $this->user()?->mentorsParticipantInSession($submission->participant, $submission->trainingSession) === true;
    }

    public function rules(): array
    {
        return [
            'second_reader_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn (Builder $q): Builder => $q->whereIn('role', [UserRole::Coach, UserRole::Admin])->where('is_active', true))],
            'second_score' => ['required', 'integer', 'min:0', 'max:100'],
            'second_reasoning' => ['required', 'string', 'max:10000'],
            'moderation_outcome' => ['nullable', 'string', 'max:100'],
            'moderation_reason' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
