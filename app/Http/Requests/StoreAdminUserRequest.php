<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')],
            'access_type' => ['required', Rule::in(['admin', 'coach', 'qa_manual', 'qa_automation', 'qa_automation_manual'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
