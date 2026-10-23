<?php

namespace App\Http\Requests;

use App\Enums\TrainingPath;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterScoringReportRequest extends FormRequest
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
            'week' => ['nullable', 'integer', Rule::exists(TrainingWeek::class, 'week_number')],
            'activity' => ['nullable', 'integer'],
            'assigned_coach' => ['nullable', 'integer'],
            'score_status' => ['nullable', 'in:scored,unscored'],
            'band' => ['nullable', 'in:Exemplary,Proficient,Developing,Beginning,Minimal'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
