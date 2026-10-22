<?php

namespace App\Http\Requests;

use App\Enums\TrainingPath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterScoringParticipantsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'path' => ['nullable', Rule::enum(TrainingPath::class)],
            'week' => ['nullable', 'integer', 'in:2,3,4'],
            'activity' => ['nullable', 'integer'],
            'score_status' => ['nullable', 'in:scored,unscored'],
            'band' => ['nullable', 'in:Exemplary,Proficient,Developing,Beginning,Minimal'],
            'assigned_to_me' => ['nullable', 'boolean'],
        ];
    }
}
