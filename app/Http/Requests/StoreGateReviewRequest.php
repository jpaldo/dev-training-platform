<?php

namespace App\Http\Requests;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\GateCriterion;
use App\Models\TrainingGate;
use App\Models\User;
use App\Services\GateReadinessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGateReviewRequest extends FormRequest
{
    /** @var ?Collection<int, GateCriterion> */
    private ?Collection $requiredCriteria = null;

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
     * Once the fields are valid, check the decision against the weekly checklist (readiness does not block it): the
     * weekly video item can be ticked only once a video is uploaded, a completed week needs every required item
     * ticked, and an incomplete week needs at least one required item left unticked.
     *
     * @return list<callable(Validator): void>
     */
    public function after(GateReadinessService $gates): array
    {
        return [function (Validator $validator) use ($gates): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $criteria = $this->requiredCriteria();
            $videoCriterion = $criteria->first(fn (GateCriterion $criterion): bool => $criterion->isWeeklyVideo());
            if ($videoCriterion && $this->criterionStatus($videoCriterion) === GateCriterionStatus::Met && $gates->weeklyVideo($this->route('participant'), $this->route('trainingGate'))['status'] === 'missing') {
                $validator->errors()->add('criteria.'.$videoCriterion->id, 'The Weekly Video Recording cannot be marked Met until a video is uploaded.');

                return;
            }
            $hasUntickedItem = $criteria->contains(fn (GateCriterion $criterion): bool => $this->criterionStatus($criterion) !== GateCriterionStatus::Met);
            $error = match ($this->enum('status', GateEvaluationStatus::class)) {
                GateEvaluationStatus::Passed => $hasUntickedItem ? 'Tick every checklist item to mark the week completed.' : null,
                GateEvaluationStatus::NeedsRevision => $hasUntickedItem ? null : 'Leave at least one item unticked to mark the week incomplete.',
                default => null,
            };
            if ($error !== null) {
                $validator->errors()->add('criteria', $error);
            }
        }];
    }

    /**
     * The mentor's decision on a checklist item; an item left out of the review is not met.
     */
    public function criterionStatus(GateCriterion $criterion): GateCriterionStatus
    {
        return $this->enum('criteria.'.$criterion->id, GateCriterionStatus::class) ?? GateCriterionStatus::NotMet;
    }

    /**
     * The decision on each of the Gate's required checklist items, keyed by criterion id (an unticked item is not met).
     *
     * @return array<int, GateCriterionStatus>
     */
    public function criterionStatuses(): array
    {
        return $this->requiredCriteria()
            ->mapWithKeys(fn (GateCriterion $criterion): array => [$criterion->id => $this->criterionStatus($criterion)])
            ->all();
    }

    /**
     * The required checklist items of the route's Gate.
     *
     * @return Collection<int, GateCriterion>
     */
    private function requiredCriteria(): Collection
    {
        /** @var TrainingGate $gate */
        $gate = $this->route('trainingGate');

        return $this->requiredCriteria ??= $gate->criteria()->where('is_required', true)->get();
    }
}
