<?php

namespace App\Http\Requests;

use App\Enums\InterviewType;
use App\Rules\ExternalBookingUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class StoreInterviewerRegistrationRequest extends FormRequest
{
    private bool $isRateLimited = false;

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
     * A rate-limited submission skips validation, so the controller answers it
     * with the "too many submissions" redirect.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isRateLimited) {
            return [];
        }

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'interview_type' => ['required', Rule::enum(InterviewType::class)],
            'mock_booking_url' => [Rule::requiredIf(fn (): bool => $this->submittedTypeOffers(InterviewType::Mock)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf(fn (): bool => $this->submittedTypeOffers(InterviewType::FinalReassessment)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
        ];
    }

    /**
     * Determine whether this submission exceeded the public registration rate limit.
     */
    public function isRateLimited(): bool
    {
        return $this->isRateLimited;
    }

    /**
     * Count the submission against the rate limit before validation, so invalid
     * submissions are limited too.
     */
    protected function prepareForValidation(): void
    {
        $key = 'public-interviewer-registration|'.$this->ip();
        $this->isRateLimited = RateLimiter::tooManyAttempts($key, 5);
        if (! $this->isRateLimited) {
            RateLimiter::hit($key, 600);
        }
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
