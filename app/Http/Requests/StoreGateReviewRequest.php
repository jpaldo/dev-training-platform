<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return ['status' => ['required', 'in:failed,needs_revision,passed'], 'comments' => ['nullable', 'string', 'max:5000'], 'criteria' => ['required', 'array'], 'criteria.*' => ['required', 'in:met,not_met']];
    }
}
