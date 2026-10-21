<?php

namespace App\Http\Requests;

use App\Enums\InterviewType;
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
        return [
            'interview_type' => ['required', Rule::enum(InterviewType::class)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'booking_url' => ['nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'mock_booking_url' => [Rule::requiredIf($this->submittedTypeOffers(InterviewType::Mock)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf($this->submittedTypeOffers(InterviewType::FinalReassessment)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * Whether the submitted interview type offers the given interview (read before validation, so any other
     * input, including a non-string one, offers none).
     */
    private function submittedTypeOffers(InterviewType $interview): bool
    {
        $type = $this->input('interview_type');

        return is_string($type) && InterviewType::tryFrom($type)?->offers($interview) === true;
    }
}
