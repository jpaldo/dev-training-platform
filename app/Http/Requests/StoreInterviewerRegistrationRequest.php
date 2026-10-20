<?php

namespace App\Http\Requests;

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
            'interview_type' => ['required', 'in:mock,final_reassessment,both'],
            'mock_booking_url' => [Rule::requiredIf(fn (): bool => in_array($this->input('interview_type'), ['mock', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf(fn (): bool => in_array($this->input('interview_type'), ['final_reassessment', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
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
}
