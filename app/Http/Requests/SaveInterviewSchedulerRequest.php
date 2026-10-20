<?php

namespace App\Http\Requests;

use App\Rules\ExternalBookingUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInterviewSchedulerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin']) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->input('interview_type');

        return [
            'interview_type' => ['required', 'in:mock,final_reassessment,both'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'booking_url' => ['nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'mock_booking_url' => [Rule::requiredIf(in_array($type, ['mock', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf(in_array($type, ['final_reassessment', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
