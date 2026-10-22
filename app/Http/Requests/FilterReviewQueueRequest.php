<?php

namespace App\Http\Requests;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TrainingPath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterReviewQueueRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isCoachOrAdmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'week' => ['nullable', 'integer', 'in:2,3,4'],
            'activity' => ['nullable', 'integer'],
            'path' => ['nullable', Rule::enum(TrainingPath::class)],
            'status' => ['nullable', Rule::in([...SubmissionStatus::awaitingReview(), SubmissionStatus::NeedsRevision, GateEvaluationStatus::NotReady, GateEvaluationStatus::Failed, GateEvaluationStatus::Passed])],
            'participant' => ['nullable', 'integer'],
        ];
    }
}
