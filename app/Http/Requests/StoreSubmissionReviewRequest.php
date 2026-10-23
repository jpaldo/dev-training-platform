<?php

namespace App\Http\Requests;

use App\Enums\ReviewDecision;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubmissionReviewRequest extends FormRequest
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'comments' => ['nullable', 'string', 'max:'.FieldLimits::REVIEW_COMMENTS],
        ];
    }
}
