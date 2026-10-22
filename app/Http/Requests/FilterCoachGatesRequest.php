<?php

namespace App\Http\Requests;

use App\Enums\GateEvaluationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterCoachGatesRequest extends FormRequest
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
            'week' => ['nullable', 'integer', 'in:2,3,4'],
            'status' => ['nullable', Rule::enum(GateEvaluationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'path' => ['nullable', 'in:manual,automation,automation_manual'],
        ];
    }
}
