<?php

namespace App\Http\Requests;

use App\Enums\RecoveryRequestStatus;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecoveryRequestRequest extends FormRequest
{
    private ?bool $hasPendingRecoveryRequest = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $submission = $this->route('activitySubmission');

        return $submission instanceof ActivitySubmission && $submission->user_id === $this->user()?->id && $submission->status === 'failed';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A submission that already has a pending recovery request skips validation,
     * so the controller answers it with the "waiting for coach" redirect.
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
        return $this->hasPendingRecoveryRequest ??= ActivityRecoveryRequest::where('activity_submission_id', $this->route('activitySubmission')->id)->where('status', RecoveryRequestStatus::Pending)->exists();
    }
}
