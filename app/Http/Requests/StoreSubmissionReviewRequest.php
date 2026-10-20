<?php

namespace App\Http\Requests;

use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $version = $this->route('submissionVersion');

        return $this->user()?->isCoachOrAdmin() === true && $version?->loadMissing('submission.participant')->submission?->participant && $this->user()->canManageParticipant($version->submission->participant);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['decision' => ['required', 'in:approved,needs_revision,rejected'], 'comments' => ['nullable', 'string', 'max:'.FieldLimits::REVIEW_COMMENTS]];
    }
}
