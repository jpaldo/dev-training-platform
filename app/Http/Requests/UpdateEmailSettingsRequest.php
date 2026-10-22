<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailSettingsRequest extends FormRequest
{
    protected $dontFlash = ['smtp_password'];

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mailer' => ['required', Rule::in(['log', 'smtp', 'sendmail', 'array'])],
            'smtp_host' => ['nullable', 'string', 'max:255', 'required_if:mailer,smtp'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535', 'required_if:mailer,smtp'],
            'smtp_scheme' => ['nullable', Rule::in(['', 'tls', 'ssl'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1000'],
            'clear_smtp_password' => ['nullable', 'boolean'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'mentor_emails_enabled' => ['required', 'boolean'],
        ];
    }
}
