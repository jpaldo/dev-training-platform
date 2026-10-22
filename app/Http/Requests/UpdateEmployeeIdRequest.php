<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeIdRequest extends FormRequest
{
    /**
     * Only a user without an employee ID may add one. Once it is set, only an admin can change it (on the user form).
     */
    public function authorize(): bool
    {
        return $this->user() !== null && blank($this->user()->employee_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('users')],
        ];
    }

    /**
     * Get the readable names of the fields.
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
