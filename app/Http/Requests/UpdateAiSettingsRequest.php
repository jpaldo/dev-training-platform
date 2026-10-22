<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'provider' => ['required', 'in:openrouter,openai,anthropic,gemini'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'model' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
        ];
    }
}
