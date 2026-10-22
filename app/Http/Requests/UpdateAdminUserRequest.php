<?php

namespace App\Http\Requests;

use App\Enums\JobRole;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /**
     * Get the validation rules that apply to the request. The employee ID stays optional so users created before it
     * existed can still be edited.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'employee_id' => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($user)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'job_role' => ['nullable', Rule::enum(JobRole::class)],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'access_type' => ['required', Rule::in(['admin', 'coach', 'qa_manual', 'qa_automation', 'qa_automation_manual'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the readable names of the user fields.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'employee ID',
        ];
    }
}
