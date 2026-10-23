<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\SubmissionVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImproveScoringFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request: an admin or the participant's current mentor in the
     * submission's session.
     */
    public function authorize(): bool
    {
        $submissionVersion = $this->route('submissionVersion');
        abort_unless($submissionVersion instanceof SubmissionVersion, 404);
        $submissionVersion->loadMissing(['submission.participant', 'submission.trainingSession']);
        abort_unless(! $submissionVersion->isDraft() && $submissionVersion->submission->participant->role === UserRole::Participant, 404);

        return $this->user()?->mentorsParticipantInSession($submissionVersion->submission->participant, $submissionVersion->submission->trainingSession) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:10000'],
            'dimension' => ['nullable', 'string', Rule::in(array_column($this->route('submissionVersion')->loadMissing('submission.activity')->submission->activity->scoringDimensions(), 'key'))],
        ];
    }
}
