<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterCoachReportsRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'program' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'mentor' => ['nullable', 'integer', Rule::exists(User::class, 'id')->where('role', UserRole::Coach)],
        ];
    }
}
