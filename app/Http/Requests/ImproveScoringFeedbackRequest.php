<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\SubmissionVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImproveScoringFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $submissionVersion = $this->route('submissionVersion');
        abort_unless($submissionVersion instanceof SubmissionVersion, 404);
        $submissionVersion->loadMissing('submission.participant');
        abort_unless($submissionVersion->status !== 'draft' && $submissionVersion->submission->participant->role === UserRole::Participant, 404);

        return $this->user()?->canManageParticipant($submissionVersion->submission->participant) === true;
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
            'dimension' => ['nullable', 'string', 'in:D1,D2,D3,D4,D5'],
        ];
    }
}
