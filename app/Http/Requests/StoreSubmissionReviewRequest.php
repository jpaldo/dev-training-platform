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
        return [
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'comments' => ['nullable', 'string', 'max:'.FieldLimits::REVIEW_COMMENTS],
        ];
    }
}
