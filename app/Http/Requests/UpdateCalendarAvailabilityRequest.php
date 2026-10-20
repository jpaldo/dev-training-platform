<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}
