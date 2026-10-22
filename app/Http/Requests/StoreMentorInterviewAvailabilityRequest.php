<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMentorInterviewAvailabilityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isCoachOrAdmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Admins must name the coach the availability belongs to; a coach's
     * availability is always their own, so `mentor_id` is ignored for them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mentor_id' => [Rule::requiredIf($this->user()?->role === UserRole::Admin), 'nullable', 'integer'],
            'available_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'slot_duration_minutes' => ['required', 'integer', 'between:15,240'],
            'capacity' => ['required', 'integer', 'between:1,20'],
        ];
    }
}
