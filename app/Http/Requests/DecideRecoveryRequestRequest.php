<?php

namespace App\Http\Requests;

use App\Enums\RecoveryRequestStatus;
use App\Models\ActivityRecoveryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideRecoveryRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $recoveryRequest = $this->route('recoveryRequest');
        abort_unless($recoveryRequest instanceof ActivityRecoveryRequest && $recoveryRequest->isPending(), 422);

        return $this->user()?->canManageParticipant($recoveryRequest->participant) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RecoveryRequestStatus::class)->only(RecoveryRequestStatus::decisions())],
            'coach_response' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get the readable names of the fields.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'coach_response' => 'mentor response',
        ];
    }
}
