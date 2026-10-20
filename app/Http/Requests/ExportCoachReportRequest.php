<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExportCoachReportRequest extends FormRequest
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
            'type' => ['nullable', 'in:progress,reviews,gates,risk'],
            'week' => ['nullable', 'integer', 'in:2,3,4'],
            'activity' => ['nullable', 'integer'],
            'path' => ['nullable', 'in:manual,automation,automation_manual'],
            'status' => ['nullable', 'in:submitted,resubmitted,under_review,needs_revision,not_ready,failed,passed'],
            'participant' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'mentor' => ['nullable', 'integer'],
        ];
    }
}
