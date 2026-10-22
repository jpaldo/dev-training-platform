<?php

namespace App\Http\Requests;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterAdminUsersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(UserRole::Admin) === true;
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
            'access_type' => ['nullable', 'in:admin,coach,qa_manual,qa_automation,qa_automation_manual'],
            'status' => ['nullable', 'in:active,disabled'],
            'path' => ['nullable', Rule::enum(TrainingPath::class)],
        ];
    }
}
