<?php

namespace App\Http\Requests;

use App\Enums\TrainingPath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'path' => ['nullable', Rule::enum(TrainingPath::class)],
            'mentor' => ['nullable', 'integer'],
            'category' => ['nullable', 'in:activities,video_recording'],
            'week' => ['nullable', 'in:2,3,4'],
            'requirement' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:not_submitted'],
        ];
    }
}
