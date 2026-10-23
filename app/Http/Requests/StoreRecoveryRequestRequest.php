<?php

namespace App\Http\Requests;

use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Services\WeekProgressionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecoveryRequestRequest extends FormRequest
{
    private ?ActivityRecoveryRequest $existingRecoveryRequest = null;

    private bool $hasLookedUpRecoveryRequest = false;

    /**
     * Determine if the user is authorized to make this request.
     *
     * A failed attempt takes one recovery request: once a coach has decided it, another request answers 422. Once the
     * attempt's session has ended, a request answers 403 ("This training session has ended."), since a retry could not
     * be worked on there.
     */
    public function authorize(): bool
    {
        $submission = $this->route('activitySubmission');

        if (! ($submission instanceof ActivitySubmission && $submission->user_id === $this->user()?->id && $submission->isFailed())) {
            return false;
        }

        app(WeekProgressionService::class)->ensureSessionAcceptsWork($this->user(), $submission->trainingSession);
        abort_if($this->existingRecoveryRequest() !== null && ! $this->hasPendingRecoveryRequest(), 422);

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A submission that already has a pending recovery request skips validation,
     * so the controller answers it with the "waiting for mentor" redirect.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->hasPendingRecoveryRequest()) {
            return [];
        }

        return [
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Determine whether the submission already has a recovery request awaiting a coach decision.
     */
    public function hasPendingRecoveryRequest(): bool
    {
        return $this->existingRecoveryRequest()?->isPending() === true;
    }

    /**
     * The recovery request already made for the failed attempt, if any.
     */
    private function existingRecoveryRequest(): ?ActivityRecoveryRequest
    {
        if (! $this->hasLookedUpRecoveryRequest) {
            $this->existingRecoveryRequest = ActivityRecoveryRequest::query()->where('activity_submission_id', $this->route('activitySubmission')->id)->latest('id')->first();
            $this->hasLookedUpRecoveryRequest = true;
        }

        return $this->existingRecoveryRequest;
    }
}
