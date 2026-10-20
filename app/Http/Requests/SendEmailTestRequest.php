<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['recipient' => ['required', 'email', 'max:255']];
    }
}
