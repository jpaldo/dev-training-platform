<?php

namespace App\Http\Requests;

use App\Enums\InterviewType;
use App\Rules\ExternalBookingUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterviewerRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'interview_type' => ['required', Rule::enum(InterviewType::class)],
            'mock_booking_url' => [Rule::requiredIf(fn (): bool => $this->submittedTypeOffers(InterviewType::Mock)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf(fn (): bool => $this->submittedTypeOffers(InterviewType::FinalReassessment)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
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
