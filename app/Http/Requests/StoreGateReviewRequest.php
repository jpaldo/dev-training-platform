<?php

namespace App\Http\Requests;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\GateCriterion;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGateReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $participant = $this->route('participant');

        return $this->user()?->isCoachOrAdmin() === true && $participant instanceof User && $this->user()->canManageParticipant($participant);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(GateEvaluationStatus::class)->only(GateEvaluationStatus::decisions())],
            'comments' => ['nullable', 'string', 'max:5000'],
            'criteria' => ['required', 'array'],
            'criteria.*' => ['required', Rule::enum(GateCriterionStatus::class)],
        ];
    }

    /**
     * The coach's decision on a criterion; a criterion left out of the review is not met.
     */
    public function criterionStatus(GateCriterion $criterion): GateCriterionStatus
    {
        return $this->enum('criteria.'.$criterion->id, GateCriterionStatus::class) ?? GateCriterionStatus::NotMet;
    }
}
