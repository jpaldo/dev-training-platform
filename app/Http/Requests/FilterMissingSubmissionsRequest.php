<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FilterMissingSubmissionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'participant' => ['nullable', 'integer'],
            'path' => ['nullable', 'in:manual,automation,automation_manual'],
            'mentor' => ['nullable', 'integer'],
            'category' => ['nullable', 'in:general_training,daily_logs,activities,video_recording'],
            'week' => ['nullable', 'in:general,2,3,4'],
            'requirement' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:not_submitted,partial'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
