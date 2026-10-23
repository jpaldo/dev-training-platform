<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
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
            'week' => ['nullable', 'integer', Rule::exists(TrainingWeek::class, 'week_number')],
            'activity' => ['nullable', 'integer'],
            'score_status' => ['nullable', 'in:scored,unscored'],
            'band' => ['nullable', 'in:Exemplary,Proficient,Developing,Beginning,Minimal'],
            'session' => ['nullable', 'integer', Rule::exists(TrainingSession::class, 'id')],
            'mentor' => ['nullable', 'integer', Rule::exists(User::class, 'id')->where('role', UserRole::Coach)],
        ];
    }
}
