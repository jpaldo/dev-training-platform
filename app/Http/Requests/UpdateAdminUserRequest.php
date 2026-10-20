<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'access_type' => ['required', Rule::in(['admin', 'coach', 'qa_manual', 'qa_automation', 'qa_automation_manual'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
