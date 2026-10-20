<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleMentorInterviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $trainingWeek = $this->route('trainingWeek');
        $activity = $this->route('activity');
        abort_unless($trainingWeek instanceof TrainingWeek && $activity instanceof Activity && $activity->training_week_id === $trainingWeek->id, 404);

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
            'scheduled_at' => ['required', 'date_format:Y-m-d\\TH:i'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Please select your interview date and time.',
            'scheduled_at.date_format' => 'Please select a valid interview date and time.',
        ];
    }
}
