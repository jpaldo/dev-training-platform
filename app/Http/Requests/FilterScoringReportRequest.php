<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
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
            'week' => ['nullable', 'integer', Rule::exists(TrainingWeek::class, 'week_number')],
            'activity' => ['nullable', 'integer'],
            'program' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'mentor' => ['nullable', 'integer', Rule::exists(User::class, 'id')->where('role', UserRole::Coach)],
            'score_status' => ['nullable', 'in:scored,unscored'],
            'band' => ['nullable', 'in:Exemplary,Proficient,Developing,Beginning,Minimal'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
