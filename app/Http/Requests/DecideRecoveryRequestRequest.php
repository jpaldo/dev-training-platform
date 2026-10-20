<?php

namespace App\Http\Requests;

use App\Models\ActivityRecoveryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DecideRecoveryRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $recoveryRequest = $this->route('recoveryRequest');
        abort_unless($recoveryRequest instanceof ActivityRecoveryRequest && $recoveryRequest->status === 'pending', 422);

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
            'decision' => ['required', 'in:approved,declined'],
            'coach_response' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
